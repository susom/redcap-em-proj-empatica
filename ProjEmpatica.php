<?php

namespace Stanford\ProjEmpatica;

use Aws\Api\Parser\Exception\ParserException;
use ExternalModules\ExternalModules;
use \REDCap;
use DateTime;
use DateInterval;

require_once "emLoggerTrait.php";

class ProjEmpatica extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    public function __construct()
    {
        parent::__construct();
        // Other code to run when object is instantiated
    }

    public $projectId;

    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                    */
    /***************************************************************************************************************** */

    function redcap_save_record($project_id, $record, $instrument, $event_id)
    {

        $this->projectId = $project_id;
        //make sure the auto create is turned on
        $config_autocreate = $this->getProjectSetting('autocreate_rsp_participant_page', $this->projectId);

        if ($config_autocreate == true) {
            $this->autocreateRSPForm($project_id, $record, $instrument, $event_id);
        }

        $survey_pref_form = $this->getProjectSetting('participant-info-instrument', $project_id);
        $config_event = $this->getProjectSetting('trigger-event-name', $project_id);
        if (($instrument == $survey_pref_form) && ($event_id == $config_event)) {
            $this->setEmailSmsPreference($project_id, $record, $event_id);
        }

        //set up for unsubscribe
        //if unsubscribe form is selected, make updates according to selection
        //1:  disable both email and text
        //2:  check withdrawn checkbox in the ADMIN form
        //3:  check withdrawn checkbox in the ADMIN form
        $unsubscribe_form = $this->getProjectSetting('unsubscribe-instrument', $project_id);
        if (($instrument == $unsubscribe_form) && ($event_id == $config_event)) {
            $this->setUnsubscribePreference($project_id, $record, $event_id);
        }

    }


    /*******************************************************************************************************************/
    /* AUTOCREATE AND AUTOSET METHODS                                                                                              */
    /***************************************************************************************************************** */

    function setUnsubscribePreference($project_id, $record, $event_id)
    {
        //check the unsubscribe field
        //1:  disable both email and text
        //2:  check withdrawn checkbox in the ADMIN form
        //3:  check withdrawn checkbox in the ADMIN form

        $unsubscribe_field = $this->getProjectSetting('unsubscribe-field', $project_id);
        $withdraw_field = $this->getProjectSetting('withdraw-field', $project_id);

        $participant_form = $this->getProjectSetting('target-instrument', $project_id);

        $unsubscribe_value = $this->getFieldValue($project_id, $record, $event_id, $unsubscribe_field);

        $log_msg = "";

        switch ($unsubscribe_value) {
            case 1:
                $this->checkCheckbox($project_id,$participant_form, $record, $event_id, array('rsp_prt_disable_sms', 'rsp_prt_disable_email'), true);
                $log_msg = "Unsubscribe request received: text and email disabled for participant.";
                //$this->turnOffSurveyInvites($project_id, $record, $event_id);
                break;
            case 2:
            case 3:
                //TODO: there is a bug where saveData does not trigger recalc. In the meantime, just disable both email and texts
                $this->checkCheckbox($project_id,$participant_form, $record, $event_id, array('rsp_prt_disable_sms', 'rsp_prt_disable_email', 'rsp_prt_disable_portal'), true);

                //check the withdrawn checkbox field in the ADMIN form
                $this->checkCheckbox($project_id, $participant_form,$record, $event_id, array($withdraw_field));
                $log_msg = "Unsubscribe request received: withdrawn checked for participant. Email and text disabled.";
                break;
        }

        //log event
        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "Unsubscribe request updated by Snyder Covid EM",  //action
            $log_msg,  //change msg
            NULL, //sql optional
            $record, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );

    }


    /**
     * Once the participant_information form is filled out, get the survey_preference and update
     * the RSP_participant_information form
     *
     * @param $project_id
     * @param $record
     * @param $event_id
     */
    function setEmailSmsPreference($project_id, $record, $event_id) {
        $survey_pref_field = $this->getProjectSetting('survey-pref-field', $project_id);
        $params = array(
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $record,
            'fields' => array(
                $survey_pref_field,
                'rsp_prt_portal_email',
                'rsp_prt_portal_phone',
                'rsp_prt_disable_sms',
                'rsp_prt_disable_email'
            ),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        //$results = json_decode($q, true);
        //$entered_data = current($results);

        //survey_preferences are in the none repeating form
        $survey_preference = $q[$record][$event_id][$survey_pref_field];

        $log_msg  = '';
        //if the survey_preference is 1 = email, 2 = sms
        if ($survey_preference == '1') {
            //email so disable sms
            $rsp_form['rsp_prt_disable_sms___1'] = '1';
            $rsp_form['rsp_prt_disable_email___1'] = '0';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by email.";
        } else if ($survey_preference == '2') {
            //sms so disable email
            $rsp_form['rsp_prt_disable_sms___1'] = '0';
            $rsp_form['rsp_prt_disable_email___1'] = '1';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by texts.";
        } else {
            //none are set, so disable both
            //from mtg of May 15: if no preference, set it to send emails
            $rsp_form['rsp_prt_disable_sms___1'] = '1';
            $rsp_form['rsp_prt_disable_email___1'] = '0';
            $log_msg = "Converted survey_preference of $survey_preference to receive the daily survey by email.";
        }

        $target_instrument = $this->getProjectSetting('target-instrument',$project_id);

        $repeat_instance = 1;  //hardcoding as 1 since only have one config.

        $this->saveForm($project_id,$record, $event_id, $rsp_form, $target_instrument,$repeat_instance);

        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "Survey Preference updated by Snyder Covid EM",  //action
            $log_msg, //change msg
            NULL, //sql optional
            $record, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );
    }


    function autocreateRSPForm($project_id, $record, $instrument, $event_id)
    {

        $target_form = $this->getProjectSetting('triggering-instrument', $this->projectId);
        $config_event = $this->getProjectSetting('trigger-event-name', $this->projectId);


        //chaeck that instrument is the correct targeting form and event
        if (($instrument != $target_form) || ($event_id != $config_event)) {
            return;
        }

        $autocreate_logic = $this->getProjectSetting('autocreate-rsp-participant-page-logic', $this->projectId);

        //check the autocreate logic
        if (!empty($autocreate_logic)) {
            $result = REDCap::evaluateLogic($autocreate_logic, $project_id, $record, $event_id);
            if ($result !== true) {
                $this->emLog("Record $record failed autocreate logic: " . $autocreate_logic);
                return;
            }
        }

        $config_field = $this->getProjectSetting('config-field',
            $this->projectId); //name of the field that contains the config id in the participant form i.e. 'rsp_prt_config_id
        $config_id = $this->getProjectSetting('portal-config-name',
            $this->projectId); //name of the config entered in the portal ME config
        $target_instrument = $this->getProjectSetting('target-instrument', $this->projectId);


        //get the relevant data fields to check
        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'json',
            'records' => $record,
            'fields' => array(REDCap::getRecordIdField(), 'rsp_prt_config_id', $target_instrument),
            'events' => $config_event
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);


        //check that the RSP participant hasn't already been created
        //check that the config field, 'rsp_prt_config_id' has an entry with the  survey config id passed in  $config_field
        $daily_config_set = array_search($config_id, array_column($results, $config_field));
        $this->emDebug("RECID: " . $record . " KEY: " . $daily_config_set . " KEY IS NULL: " . empty($daily_config_set) . " : " . isset($daily_config_set));

        //this config name was not fouund in any instnace of rsp_participant_instance
        if (empty($daily_config_set)) {
            //creating a new instance
            $this->updateRSPParticipantInfoForm($project_id,$config_id, $record, $event_id);

        }


    }


    /**
     * Return the next instance id for this survey instrument
     *
     * Using the getDAta with return_format = 'array'
     * the returned nested array :
     *  $record
     *    'repeat_instances'
     *       $event
     *          $instrument
     *
     *
     * @return int|mixed
     */
    public function getNextRepeatingInstanceID($record, $instrument, $event) {


        $this->emDebug($record . " instrument: ".  $instrument. " event: ".$event);
        //getData for all surveys for this reocrd
        //get the survey for this day_number and survey_data
        //TODO: return_format of 'array' returns nothing if using repeating events???
        //$get_data = array('redcap_repeat_instance');
        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'array',
            'fields' => array('redcap_repeat_instance', 'rsp_prt_start_date', $instrument . "_complete"),
            'records' => $record
            //'events'              => $this->portalConfig->surveyEventID
        );
        $q = REDCap::getData($params);
        //$results = json_decode($q, true);

        $instances = $q[$record]['repeat_instances'][$event][$instrument];
        //$this->emDebug($params, $q, $instances);


        ///this one is for standard using array
        $max_id = max(array_keys($instances));

        //this one is for longitudinal using json
        //$max_id = max(array_column($results, 'redcap_repeat_instance'));

        return $max_id + 1;
    }



    function updateRSPParticipantInfoForm($project_id, $config_id, $record, $event_id)
    {
        //$target_form          = $this->getProjectSetting('triggering-instrument');
        // $config_field         = $this->getProjectSetting('portal-config-name');

        $config_event = $this->getProjectSetting('trigger-event-name', $this->projectId);
        $target_instrument = $this->getProjectSetting('target-instrument', $this->projectId);

        //get the date to enter for the start date
        //Alessandra  to manually enter this date for Empatica so remove
        //$default_date = $this->getProjectSetting('default-start-date', $this->projectId);

        //format the default date of the survey portal start
        //$start_date = new DateTime($default_date);
        //$start_date_str = $start_date->format('Y-m-d');

        //get the email and phone number from the consent form.
        $email_field = $this->getProjectSetting('email-field', $this->projectId);
//Not used for Empatica
//        $phone_field = $this->getProjectSetting('phone-field', $this->projectId);

        $params = array(
            'project_id' => $this->projectId,
            'return_format' => 'json',
            'records' => $record,
            'fields' => array($email_field),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);
        $enter_data = current($results);

        //todo should this just be hardcoded to 1?
        //$next_repeat_instance = $this->getNextRepeatingInstanceID($record, $target_instrument,$config_event);
        $next_repeat_instance = 1;
        $this->emDebug("NEXT Repeating Instance ID for  ".$record ." IS ".$next_repeat_instance);

        if (!isset($target_instrument)) {
            $this->emError("Target instrument is not set in the EM config. Data will not be transferred. Set config for target-instrument.");
            return false;
        }

        $data_array = array(
            'rsp_prt_portal_email' => $enter_data[$email_field],
//            'rsp_prt_portal_phone' => $enter_data[$phone_field],
//            'rsp_prt_start_date'  => $start_date_str,
            'rsp_prt_disable_sms___1'   => '1',  //when initially created, set disable to true (this will reset in participant info form
            'rsp_prt_disable_email___1' => '1',  //ditto
            'rsp_prt_config_id'         => $config_id //i.e. 'daily'
        );


        //save the data
        $save_msg = $this->saveForm($project_id, $record, $config_event, $data_array, $target_instrument,$next_repeat_instance);

        //trigger the hash creation and sending of the email by triggering the redcap_save_record hook on  the rsp_participant_info form
        // \Hooks::call('redcap_save_record', array($child_pid, $child_id, $_GET['page'], $child_event_name, $group_id, null, null, $_GET['instance']));
        \Hooks::call('redcap_save_record', array($project_id, $record, $target_instrument, $config_event, null, null, null, $next_repeat_instance));
    }

    /*******************************************************************************************************************/
    /*  METHODS                                                                                                        */
    /***************************************************************************************************************** */

    function getFieldValue($project_id, $record, $event_id,  $get_field) {
        $params = array(
            'project_id' => $project_id,
            'return_format' => 'array',
            'records' => $record,
            'fields' => array($get_field),
            'events' => $event_id
        );

        $q = REDCap::getData($params);
        //$results = json_decode($q, true);
        //$entered_data = current($results);

        //return the field
        return $q[$record][$event_id][$get_field];


    }


    function checkCheckbox($project_id, $instrument, $record, $event_id, $checkbox_field, $repeating = false) {
        //set the checkbox in the form
        $event_name = REDCap::getEventNames(true, false, $event_id);

        $checkboxes = array();

        foreach ($checkbox_field as $k => $v) {
            $checkboxes[$v."___1"] = 1;
        }

        $save_data = array(
            'record_id'         => $record,
            'redcap_event_name' => $event_name,
        );

        if ($repeating) {
            $repeat_array = array(
                "redcap_repeat_instance" => 1,
                "redcap_repeat_instrument" => $instrument
            );
        } else {
            $repeat_array = array();
        }

        //$save_data = array_replace($save_data, $checkboxes, $repeating ? array("redcap_repeat_instance"=>1) : array());
        $save_data = array_replace($save_data, $checkboxes, $repeat_array);

        $status = REDCap::saveData('json', json_encode(array($save_data)));


        if (!empty($status['errors'])) {
            $this->emDebug("Error trying to save this data",$save_data,  $status['errors']);
        }

    }

    function saveForm($project_id, $record_id, $event_id, $data_array, $instrument,$repeat_instance)
    {
        //$instrument = 'rsp_participant_info';

        //because we will hit this code from different project context we need to get the correct event name to save.
        $proj = new \Project($this->projectId);
        $name = $proj->getUniqueEventNames($event_id);


        $params = array(
            REDCap::getRecordIdField() => $record_id,
            'redcap_event_name' => $name,
            'redcap_repeat_instrument' => $instrument,
            'redcap_repeat_instance' => $repeat_instance
        );

        $data = array_merge($params, $data_array);

        $result = REDCap::saveData($this->projectId, 'json', json_encode(array($data)));
        if ($result['errors']) {
            $this->emError($result['errors'], $params);
            $msg = "Error while trying to save date to  $instrument instance $repeat_instance.";
            //return false;
        } else {
            $msg = "Successfully saved data to $instrument instance $repeat_instance.";
        }

        //add entry into redcap logging about saved form
        REDCap::logEvent(
            "RSP Participant Info page created by Snyder Covid EM",  //action
            $msg,  //change msg
            NULL, //sql optional
            $record_id, //record optional
            $event_id, //event optional
            $project_id //project ID optional
        );

        return $msg;

    }



}
