<?php
/**
 * Copyright (c) STMicroelectronics 2012. All rights reserved
 *
 * Tuleap is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * Tuleap is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Tuleap. If not, see <http://www.gnu.org/licenses/>.
 */

require_once('Tracker_DateReminder.class.php');
require_once('dao/Tracker_DateReminderDao.class.php');
require_once('FormElement/Tracker_FormElementFactory.class.php');
require_once 'common/date/DateHelper.class.php';

class Tracker_DateReminderManager {

    protected $tracker;

    /**
     * Constructor of the class
     *
     * @param Tracker $tracker Tracker associated to the manager
     *
     * @return Void
     */
    public function __construct(Tracker $tracker) {
        $this->tracker = $tracker;
    }

    /**
     * Obtain the tracker associated to the manager
     *
     * @return Tracker
     */
    public function getTracker(){
        return $this->tracker;
    }

    /**
     * Process nightly job to send reminders
     *
     * @return Void
     */
    public function process() {
        $reminders = $this->getTrackerReminders();
        foreach ($reminders as $reminder) {
            $artifacts = $this->getArtifactsByreminder($reminder);
            foreach ($artifacts as $artifact) {
                $this->sendReminderNotification($reminder, $artifact);
            }
        }
    }

    /**
     * Send reminder
     *
     * @param Tracker_DateReminder $reminder Reminder that will send notifications
     * @param Tracker_Artifact $artifact Artifact for which reminders will be sent
     *
     * @return Void
     */
    protected function sendReminderNotification(Tracker_DateReminder $reminder, Tracker_Artifact $artifact) {
        $tracker    = $this->getTracker();
        // 1. Get the recipients list
        $recipients = $reminder->getRecipients();

        // 2. Compute the body of the message + headers
        $messages = array();
        $um       = $this->getUserManager();
        foreach ($recipients as $recipient) {
            $user = null;
            $user = $um->getUserByUserName($recipient);
            if ($user && $artifact->userCanView($user) && $reminder->getDateField()->userCanRead($user)) {
                $this->buildMessage($reminder, $artifact, $messages, $user);
            }
        }

        // 3. Send the notification
        foreach ($messages as $m) {
            $this->sendReminder($artifact, $m['recipients'], $m['headers'], $m['subject'], $m['htmlBody'], $m['txtBody']);
        }
    }

    /**
     * Build the reminder messages
     *
     * @param Tracker_DateReminder $reminder Reminder that will send notifications
     * @param Tracker_Artifact $artifact Artifact for which reminders will be sent
     * @param Array            $messages Messages
     * @param User             $user     Receipient
     *
     * return Array
     */
    protected function buildMessage(Tracker_DateReminder $reminder, Tracker_Artifact $artifact, &$messages, $user) {
        $mailManager = new MailManager();

        $recipient = $user->getEmail();
        $lang      = $user->getLanguage();
        $format    = $mailManager->getMailPreferencesByUser($user);

        //We send multipart mail: html & text body in case of preferences set to html
        $htmlBody = '';
        if ($format == Codendi_Mail_Interface::FORMAT_HTML) {
            $htmlBody  .= $this->getBodyHtml($reminder, $user, $lang);
        }
        $txtBody = $this->getBodyText($reminder, $user, $lang);

        $subject   = $this->getSubject($user);
        $headers   = array(); 
        $hash = md5($htmlBody . $txtBody . serialize($headers) . serialize($subject));
        if (isset($messages[$hash])) {
            $messages[$hash]['recipients'][] = $recipient;
        } else {
            $messages[$hash] = array(
                    'headers'    => $headers,
                    'htmlBody'   => $htmlBody,
                    'txtBody'    => $txtBody,
                    'subject'    => $subject,
                    'recipients' => array($recipient),
            );
        }
    }
    
    /**
     * Send a notification
     *
     * @param Array  $recipients the list of recipients
     * @param Array  $headers    the additional headers
     * @param String $subject    the subject of the message
     * @param String $htmlBody   the html content of the message
     * @param String $txtBody    the text content of the message
     *
     * @return Void
     */
    protected function sendReminder(Tracker_Artifact $artifact, $recipients, $headers, $subject, $htmlBody, $txtBody) {
        $mail = new Codendi_Mail();
        $hp = Codendi_HTMLPurifier::instance();
        $breadcrumbs = array();
        $groupId = $this->getTracker()->getGroupId();
        $project = $this->getTracker()->getProject();
        $trackerId = $this->getTracker()->getID();
        $artifactId = $artifact()->getID();

        $breadcrumbs[] = '<a href="'. get_server_url() .'/projects/'. $project->getUnixName(true) .'" />'. $project->getPublicName() .'</a>';
        $breadcrumbs[] = '<a href="'. get_server_url() .'/plugins/tracker/?tracker='. (int)$trackerId .'" />'. $hp->purify(SimpleSanitizer::unsanitize($this->getTracker()->getName())) .'</a>';
        $breadcrumbs[] = '<a href="'. get_server_url().'/plugins/tracker/?aid='.(int)$artifactId.'" />'. $hp->purify($this->getTracker()->getName().' #'.$artifactId) .'</a>';

        $mail->getLookAndFeelTemplate()->set('breadcrumbs', $breadcrumbs);
        $mail->getLookAndFeelTemplate()->set('title', $hp->purify($subject));
        $mail->setFrom($GLOBALS['sys_noreply']);
        $mail->addAdditionalHeader("X-Codendi-Project",     $this->artifact()->getTracker()->getProject()->getUnixName());
        $mail->addAdditionalHeader("X-Codendi-Tracker",     $this->artifact()->getTracker()->getItemName());
        $mail->addAdditionalHeader("X-Codendi-Artifact-ID", $this->getId());
        foreach($headers as $header) {
            $mail->addAdditionalHeader($header['name'], $header['value']);
        }
        $mail->setTo(implode(', ', $recipients));
        $mail->setSubject($subject);
        if ($htmlBody) {
            $mail->setBodyHTML($htmlBody);
        }
        $mail->setBodyText($txtBody);
        $mail->send();
    }

    /**
     * Get the subject for reminder
     *
     * @param String $recipient The recipient who will receive the reminder
     *
     * @return String
     */
    public function getSubject($recipient) {
        $s = "[" . $this->tracker->getTrackerName()."] ".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','subject', array($this->reminder->getLabel(),date("j F Y",$this->reminder->getDateValue()), $this->artifact->getSummary()));
        return $s;
    }

    /**
     * Get the text body for notification
     *
     * @param Tracker_DateReminder $reminder Reminder that will send notifications
     * @param Tracker_Artifact $artifact
     * @param String  $recipient    The recipient who will receive the notification
     * @param BaseLanguage $language The language of the message
     *
     * @return String
     */
    protected function getBodyText(Tracker_DateReminder $reminder, Tracker_Artifact $artifact, $recipient, BaseLanguage $language) {
        $proto = ($GLOBALS['sys_force_ssl']) ? 'https' : 'http';
        $link .= ' <'. $proto .'://'. $GLOBALS['sys_default_domain'] .TRACKER_BASE_URL.'/?aid='. $artifact->getId() .'>';
        $week = date("W", $this->reminder->getValue());

        $output = '+============== '.'['.$this->getTracker()->getItemName() .' #'. $artifact->getId().'] '.$artifact->fetchMailTitle($recipient, $format, false).' ==============+';
        $output .= PHP_EOL;
    
        $output = "\n".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','body_header',array('codex', $reminder->getField->getLabel(),date("l j F Y",$reminder->getField->getDateValue()), $week)).
            "\n\n".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','body_project',array($group->getPublicName())).
            "\n".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','body_tracker',array($this->tracker->getTrackerName())).
            "\n".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','body_art',array($artifact->getSummary())).
            "\n".$this->reminder->getLabel().": ".date("D j F Y", $reminder->getField->getValue()).
            "\n\n".$GLOBALS['Language']->getText('plugin_tracker_date_reminder','body_art_link').
            "\n".$link."\n";
        return $output;
    }

    /**
     * Get the html body for notification
     *
     * @param Tracker_DateReminder $reminder Reminder that will send notifications
     * @param String  $recipient    The recipient who will receive the reminder
     * @param BaseLanguage $language The language of the message
     *
     * @return String
     */
    protected function getBodyHtml(Tracker_DateReminder $reminder, $recipient, BaseLanguage $language) {
        //TODO
        return $output;
    }

    /**
     * New date reminder form
     *
     * @return String
     */
    public function getNewDateReminderForm() {
        $before = '';
        $after  = '';
        //@todo Call dateReminder insertion method within a dedicated action (say insert_reminder) at Tracker_NotificationsManager::process() (around line 57)
        $output .= '<FORM ACTION="'.TRACKER_BASE_URL.'/?func=admin-notifications&amp;tracker='. (int)$this->tracker->id .'&amp;action=new_reminder" METHOD="POST" name="date_field_reminder_form">';
        $output .= '<INPUT TYPE="HIDDEN" NAME="group_id" VALUE="'.$this->tracker->group_id.'">
                    <INPUT TYPE="HIDDEN" NAME="tracker_id" VALUE="'.$this->tracker->id.'">';
        $output .= '<table border="0" width="900px"><TR height="30">';
        $output .= '<TD> <INPUT TYPE="TEXT" NAME="distance" SIZE="3"> day(s)</TD>';
        $output .= '<TD><SELECT NAME="notif_type">
                        <OPTION VALUE="0" '.$before.'> before
                        <OPTION VALUE="1" '.$after.'> after
                    </SELECT></TD>';
        $output .= '<TD>'.$this->getTrackerDateFields().'</TD>';
        $output .= '<TD>'.$this->getUgroupsAllowedForTracker().'</TD>';
        $output .= '<TD><INPUT type="submit" name="submit" value="'.$GLOBALS['Language']->getText('plugin_tracker_include_artifact','submit').'"></TD>';
        $output .= '</table></FORM>';
        return $output;
    }

    /**
     * Build a multi-select box of ugroup selectable to fill the new date field reminder.
     * It contains: all dynamic ugroups plus project members and admins.
     * @TODO check permissions on tracker, date field before display??
     *
     * @return String
     */
    protected function getUgroupsAllowedForTracker() {
        $res = ugroup_db_get_existing_ugroups($this->tracker->group_id, array($GLOBALS['UGROUP_PROJECT_MEMBERS'],
                                                                              $GLOBALS['UGROUP_PROJECT_ADMIN']));
        $output  = '<SELECT NAME="reminder_ugroup" multiple>';
        while($row = db_fetch_array($res)) {
            $output .= '<OPTION VALUE="'.$row['ugroup_id'].'">'.util_translate_name_ugroup($row['name']).'</OPTION>';
        }
        $output  .= '</SELECT>';
        return $output;
    }

    /**
     * Build a select box of all date fields used by a given tracker
     *
     * @return String
     */
    protected function getTrackerDateFields() {
        $tff = Tracker_FormElementFactory::instance();
        $trackerDateFields = $tff->getUsedDateFields($this->tracker);
        $ouptut  = '<select name="reminder_field_date">';
        foreach ($trackerDateFields as $dateField) {
            $ouptut .= '<option value="'. $dateField->getId() .'" '. $selected.'>'.$dateField->getLabel().'</option>';
        }
        $ouptut .= '</select>';
        return $ouptut;
    }

    /**
     * Retrieve all date reminders for a given tracker
     *
     * @return DataAccessResult
     */
    public function getTrackerReminders() {
        $reminderManagerDao = $this->getDao();
        return $reminderManagerDao->getDateReminders((int)$this->tracker->id);
    }

    /**
     * Add new reminder
     * @TODO check request params before insertion
     * 
     * @param HTTPRequest $request request object
     *
     * @return Boolean
     */
    public function addNewReminder(HTTPRequest $request) {
        $trackerId = $request->get('tracker_id');
        $fieldId   = $request->get('reminder_field_date');
        $notificationType = $request->get('notif_type');
        $ugroupId  = $request->get('reminder_ugroup');
        $distance = $request->get('distance');
        $reminderManagerDao = $this->getDao();
        return $reminderManagerDao->addDateReminder($trackerId, $fieldId, $ugroupId, $notificationType, $distance);
    }

    /**
     * Delete a list of date reminders
     *
     * @param Array $remindersIds List of Id of reminders
     *
     * @return Boolean
     */
    public function deleteTrackerReminders($remindersIds) {
        $reminderManagerDao = $this->getDao();
        return $reminderManagerDao->deleteReminders($remindersIds);
    }

    /**
     * Build a reminder instance
     *
     * @param array $row The data describing the reminder
     *
     * @return Tracker_DateReminder
     */
    public function getInstanceFromRow($row) {
        return new Tracker_DateReminder($row['reminder_id'],
                                          $row['tracker_id'],
                                          $row['field_id'],
                                          $row['ugroup_id'],
                                          $row['notification_type'],
                                          $row['distance'],
                                          $row['status']);
    }

    /**
     * Get the Tracker_DateReminder dao
     *
     * @return Tracker_DateReminderDao
     */
    protected function getDao() {
        return new Tracker_DateReminderDao();
    }

    /**
     * Get the reminder
     *
     * @param Integer  $reminderId    The reminder id
     *
     * @return Tracker_DateReminder
     */
    public function getReminder($reminderId) {
        if ($row = $this->getDao()->searchById($reminderId)->getRow()) {
            return $this->getInstanceFromRow($row);
        }
        return null;
    }

    /** Get artifacts that will send notification for a reminder
     *
     * @param Tracker_DateReminder $reminder Reminder on which the notification is based on
     *
     * @return Array
     */
    public function getArtifactsByreminder(Tracker_DateReminder $reminder) {
        $artifacts = array();
        $date = DateHelper::getDistantDateFromToday($reminder->getDistance(), $reminder->getNotificationType());
        // @TODO: Include "last update date" & "submitted on" as types of date fields
        $dao = new Tracker_FormElement_Field_Value_DateDao();
        $dar = $dao->getArtifactsByFieldAndValue($reminder->getFieldId(), $date);
        if ($dar && !$dar->isError()) {
            $artifactFactory = Tracker_ArtifactFactory();
            foreach ($dar as $row) {
                $artifacts[] = $artifactFactory->getArtifactById($row['artifact_id']);
            }
        }
        return $artifacts;
    }

    /** Display all reminders for a given tracker
     *
     * @return Void
     */
    public function displayAllReminders() {
        $titles = array('Reminder',
                        $GLOBALS['Language']->getText('plugin_tracker_date_reminder','notification_status'),
                        $GLOBALS['Language']->getText('plugin_tracker_date_reminder','notification_settings'),
                        $GLOBALS['Language']->getText('global', 'delete'));
        $i=0;
        $trackerReminders = $this->getTrackerReminders();
        print html_build_list_table_top($titles);
        foreach ($trackerReminders as $reminder) {
            $reminder = $this->getReminder($reminder['reminder_id']);
            print '<tr class="'.util_get_alt_row_color($i++).'">';
            print '<td>';
            print $reminder;
            print '</td>';
            print '<td>'.$reminder->status.'</td>';
            print '<td>'.$reminder->notificationType.'</td>';
            print '<td><a href="?func=admin-notifications&amp;tracker='.(int)$this->tracker->id.'&amp;action=delete_reminder&amp;reminder_id='.$reminder->reminderId.'">'. $GLOBALS['Response']->getimage('ic/trash.png') .'</a></td>';
            print '</tr>';
        }
        print '</TABLE>';
    }
}

?>