<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Hash;
use Exception;

use App\Services\Salesforce as Salesforce;
use App\Services\FedEx as FedEx;
use App\Services\ManageUserStory as ManageUserStory;
use App\Mail\TestClickEmail;
use App\Mail\LogEmail;

use App\Models\Error;
use App\Models\Device;
use App\Models\ClickLog;
use App\Models\ClickType;
use App\Models\Status;
use App\Models\TaskType;
use App\Models\User;
use App\Models\UserStory;
use App\Models\ActionType;
use App\Models\DeviceUserLog;
use App\Models\TaskLog;

/*
|-------------------------------------------------------------------
| API: IoT Button Click
|-------------------------------------------------------------------
|
| Parses details about the button which was clicked. Determines why
| the button was clicked based on device and user status. Then
| decides next actions to take. Logs steps taken.
|
*/

class DeviceController extends Controller
{
    public function __construct() {
        $this->middleware('CompanyAWSAuth');
    }

    public function press(Request $r){
        try {
            // attempt to figure out what device was pressed
            $device = $this->parseDevice($r);
            $salesforce = new Salesforce;
            
            if(isset($device) && $device->is_active && isset($device->user) && !($device->is_demo)){
                
                $manageUserStory = new ManageUserStory($device->user, $device);
                
                if($device->is_shipped){

                    if($device->is_delivered){
                        
                        if($device->is_activated){

                            $dLog = new DeviceUserLog;
                            $dLog->device_id = $device->device_id;
                            
                            // check current device status
                            switch($device->status_id){
                                
                                case Status::Ready:
                                    $dLog->action_type_id = ActionType::Device_Press_Dosing;
                                    $manageUserStory->add(ActionType::Device_Press_Dosing);
                                    $device->status_id = Status::Dosed;
                                    $device->save();
                                    break;
                                
                                case Status::Dosed:
                                    $dLog->action_type_id = ActionType::Device_Press_Dosing_Duplicate;
                                    $manageUserStory->add(ActionType::Device_Press_Dosing_Duplicate);
                                    break;
                                
                                case Status::Warning:
                                    // user took medication and should no longer be considered in a warning state
                                    $device->status_id = Status::Dosed;
                                    $device->save();
                                    $dLog->action_type_id = ActionType::Device_Press_Dosing_From_Warning;
                                    $manageUserStory->add(ActionType::Device_Press_Dosing_From_Warning);
                                    break;
                                
                                case Status::Escalated:
                                    // user took medication and should no longer be considered in an escalation state
                                    // the callcenter task should be removed as well
                                    
                                    $salesforce->removeTask($device->sf_task_id);
                                    $manageUserStory->add(ActionType::Device_Press_Dosing_From_Escalation);
                                    $manageUserStory->add(ActionType::GRC_Remove_Task_Missed_Dose);
                                    $dLog->action_type_id = ActionType::Device_Press_Dosing_From_Escalation;

                                    // update the device
                                    $device->status_id = Status::Dosed;
                                    $device->sf_task_id = null;
                                    $device->save();
                                    
                                    break;
                                
                                case Status::Hold:
                                    
                                    $device->status_id = Status::Dosed;
                                    $device->save();

                                    $salesforce->createTask(TaskType::DosingResumed, $device, $device->user);
                                    $manageUserStory->add(ActionType::Device_Press_Dosing_From_Hold);
                                    $manageUserStory->add(ActionType::GRC_Add_Task_Dosing_Resumed);
                                    $dLog->action_type_id = ActionType::Device_Press_Dosing_From_Hold;
                                    break;
                            }

                            // increment the user click counter for dosing and activation clicks
                            $device->clicks_user = $device->clicks_user+1;
                            $device->save();
                            $dLog->save();

                            return "Dosing Click";
                        }
                        else {
                            // increment the user click counter for dosing and activation clicks
                            $device->clicks_user = $device->clicks_user+1;
                            $device->save();

                            // close any tasks for the activation failure
                            if(isset($device->sf_task_id)) { $salesforce->removeTask($device->sf_task_id); }

                            // activation of button
                            $this->activateDevice($device);
                            $manageUserStory->add(ActionType::Device_Activation);

                            $dLog = new DeviceUserLog;
                            $dLog->device_id = $device->device_id;
                            $dLog->action_type_id = ActionType::Device_Activation;
                            $dLog->save();

                            $manageUserStory->add(ActionType::GRC_Add_Task_Welcoming);

                            return "Activated Device";
                        }
                    }
                    else if(isset($device->tracking_id)) {
                        
                        // check Fedex for delivery or ignore click
                        $fedEx = new FedEx;
                        $trackObj = $fedEx->track($device->tracking_id);
                        
                        if($trackObj->is_delivered) {
                            $device->is_delivered = true;
                            $device->delivered_at = $trackObj->delivered_at;
                            $device->delivery_details = json_encode(array(
                                "delivered_signature_name" => $trackObj->delivered_signature_name,
                                "delivery_attempts" => $trackObj->delivery_attempts
                            ));
                            $device->save();

                            // activation of button
                            $this->activateDevice($device);
                            $manageUserStory->add(ActionType::Device_Delivery, null, $device->delivered_at);
                            sleep(1);
                            $manageUserStory->add(ActionType::Device_Activation);

                            $dLog = new DeviceUserLog;
                            $dLog->device_id = $device->device_id;
                            $dLog->action_type_id = ActionType::Device_Activation;
                            $dLog->save();

                            sleep(1);
                            $manageUserStory->add(ActionType::GRC_Add_Task_Welcoming);

                            return "Mark delivered and activated";
                        }
                        else {
                            // ignore click - because it probably get pressed in transit
                            $device->delivery_details = json_encode(array(
                                "delivery_estimate" => $trackObj->delivery_estimate,
                                "delivery_attempts" => $trackObj->delivery_attempts
                            ));
                            $device->save();
                            $manageUserStory->add(ActionType::Device_Press_Ignore_In_Delivery);

                            return "Not delivered, ignored";
                        }
                    }
                    else {
                        // tracking id is not set, this is a problem - need to check delivery!
                        $errorLog = new Error;
                        $errorLog->error_type = Error::FedExMissingTrackingId;
                        $errorLog->message = "Device is marked as shipped but no tracking id to check if delivered.";
                        $errorLog->details = $r->getContent();
                        $errorLog->save();

                        // send email log
                        $this->sendEmailLog("A device was clicked and not yet delivered BUT the tracking Id is missing!. There is no way to determine if the device was delivered. Please add the Tracking Id to the deployment page.", "Client error", $device->user, $device);

                        return "No Tracking Id to Check - Failed to verify next steps. See error log.";
                    }
                }
                else {
                    // this is a test click - send an email
                    try {
                        $result = Mail::to(config('company.shipping_email.to'))->send(new TestClickEmail(
                            $device->user, $device, config('company.shipping_email.from'), 'Client Test Click'
                        ));
                        return "Test Click";
                    }
                    catch(Exception $e){
                        $success = false;

                        // log the error
                        $log = new Error;
                        $log->error_type = Error::TestClick;
                        $log->message = $e->getMessage();
                        if(isset($device->user)){ $log->user_id = $device->user->user_id; }
                        $log->save();

                        return "Test Click Email Error: " . $e->getMessage();
                    }
                }
            }
            else if(isset($device) && !isset($device->user) && $device->is_active) {
                // user is not set, this is a problem
                $errorLog = new Error;
                $errorLog->error_type = Error::DeviceNotAssigned;
                $errorLog->message = "Device is not assigned to a user";
                $errorLog->details = $r->getContent();
                $errorLog->save();

                // send email
                $this->sendEmailLog("A user is not assigned to the device that was clicked.", "Client Error", $device->user, $device);
                return "No user assigned to device";
            }
            else {
                $this->sendEmailLog("An inactive device was clicked.", "Client Warning", $device->user, $device);
                return "Device is not active";
            }
        }
        catch(Exception $e){
            $errorLog = new Error;
            $errorLog->error_type = Error::DevicePress;
            $errorLog->message = $e->getMessage();
            $errorLog->details = $r->getContent();
            $errorLog->save();

            return "error";
        }
    }

    private function activateDevice(Device $device){
        $device->is_activated = true;
        $device->activated_at = gmdate("Y-m-d H:i:s");
        $device->save();

        // create task for callcenter to welcome the new user
        $salesforce = new Salesforce;
        $salesforce->createTask(TaskType::Welcome, $device, $device->user);
    }

    private function parseDevice($request){

        $log = new ClickLog;
        $log->serial_number = null;
        $log->raw_json = $request->getContent();

        $device = null;

        try {
            $clickType = "";

            if(isset($request->deviceInfo)){
                // assume enterprise 1-click Button
                $log->serial_number = $request->deviceInfo["deviceId"];
                $log->battery = $request->deviceInfo["remainingLife"];
                $clickType = $request->deviceEvent["buttonClicked"]["clickType"];

                switch(strtoupper($clickType)){
                    case "SINGLE":
                        $log->click_type_id = ClickType::SingleClick;
                        break;
                    case "DOUBLE":
                        $log->click_type_id = ClickType::DoubleClick;
                        break;
                    case "LONG":
                        $log->click_type_id = ClickType::LongClick;
                        break;
                }
                $log->save();

                $device = Device::where("serial_number", $log->serial_number)
                                ->with("user")
                                ->first();
                
                if(isset($device)){
                    // update device with recent click values
                    $device->battery = $log->battery;
                    $device->last_pressed_at = gmdate("Y-m-d H:i:s");
                    $device->last_click_type_id = $log->click_type_id;
                    $device->clicks_total = $device->clicks_total+1;
                    $device->save();
                }
                else {
                    // device not in database
                    $errorLog = new Error;
                    $errorLog->error_type = Error::DeviceNotSetup;
                    $errorLog->message = "Device is not setup";
                    $errorLog->details = $request->getContent();
                    $errorLog->save();
                }
            }
            else {
                $errorLog = new Error;
                $errorLog->error_type = Error::PressDataUnknown;
                $errorLog->message = "Received click data is in an unknown format";
                $errorLog->details = $request->getContent();
                $errorLog->save();
            }
        }
        catch(Exception $e){
            $logsuccess = false;

            $errorLog = new Error;
            $errorLog->error_type = Error::DeviceParsing;
            $errorLog->message = $e->getMessage();
            $errorLog->details = $request->getContent();
            $errorLog->save();
        }

        return $device;
    }

    private function sendEmailLog($messageLog, $subject, $user, $device){
        try {
            $result = Mail::to(config('company.shipping_email.to'))->send(new LogEmail(
                $messageLog, $user, $device, config('company.shipping_email.from'), $subject
            ));
        }
        catch(Exception $e){
            return $e->getMessage();
        }
    }
}
