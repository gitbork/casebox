<?php
namespace CB\Objects;

use CB\DB;
use CB\Util;
use CB\User;
use CB\L;

class Task extends Object
{
    // define possible task status constants
    public static $STATUS_NONE = 0;
    public static $STATUS_OVERDUE = 1;
    public static $STATUS_ACTIVE = 2;
    public static $STATUS_CLOSED = 3;
    public static $STATUS_PENDING = 4; //for dependent tasks

    // define possible user statuses in tasks
    public static $USERSTATUS_NONE = 0; //isn't assigned to task
    public static $USERSTATUS_ONGOING = 1;
    public static $USERSTATUS_DONE = 2;

    /**
     * create a task with specified params
     * @param  array $p object properties
     * @return int   created id
     */
    public function create($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->data = $p;

        $this->setParamsFromData($p);

        return parent::create($p);
    }

    /**
     * load custom data for tasks
     * Note: should be removed after tasks upgraded and custom task tables removed
     */
    protected function loadCustomData()
    {
        parent::loadCustomData();

        $d = &$this->data;

        if (empty($d['data'])) {
            $d['data'] = array();
        }

        $cd = &$d['data']; //custom data
        $sd = &$d['sys_data']; //sys_data

        $this->upgradeTaskData();

        /* add possible action flags*/
        \CB\Tasks::setTaskActionFlags($d);
    }

    /**
     * Specific / temporar method to upgrade task data from old format to new one
     * @return void
     */
    public function upgradeTaskData()
    {
        $d = &$this->data;

        if (empty($d['data'])) {
            $d['data'] = array();
        }

        $cd = &$d['data']; //custom data
        $sd = &$d['sys_data']; //sys_data

        if (isset($sd['task_status'])) {
            return false; // task already upgraded
        }

        $res = DB\dbQuery(
            'SELECT t.title `_title`
                ,t.date_start
                ,t.date_end
                ,t.allday
                ,t.responsible_user_ids `assigned`
                ,t.description
                ,t.status
                -- ,(SELECT reminds FROM tasks_reminders WHERE task_id = $1 AND user_id = $2) reminds
                ,DATE_FORMAT(t.completed, \'%Y-%m-%dT%H:%i:%sZ\') `task_d_closed`
            FROM tasks t
            WHERE t.id = $1',
            array(
                $this->id
                ,$_SESSION['user']['id']
            )
        ) or die(DB\dbQueryError());

        if ($r = $res->fetch_assoc()) {
            //set status
            $sd['task_status'] = $r['status'];

            if (!empty($r['task_d_closed'])) {
                $sd['task_d_closed'] = $r['task_d_closed'];
            }

            unset($r['status']);
            unset($r['user_status']);
            unset($r['task_d_closed']);

            //transform date fields with allday flag
            $sd['task_allday'] = $r['allday'];

            $d['data']['_title'] = $r['_title'];

            $sd['task_due_date'] = Util\dateMysqlToISO($r['date_end']);
            $d['data']['due_date'] = Util\dateMysqlToISO($r['date_end']);

            $d['data']['assigned'] = $r['assigned'];
            $d['data']['description'] = $r['description'];

            if ($r['allday'] == -1) {
                $sd['task_due_time'] = Util\dateMysqlToISO($r['date_end']);
                $d['data']['due_time'] = substr(Util\dateMysqlToISO($r['date_end']), 12, 8);
            }

            unset($r['allday']);
            unset($r['date_start']);
            unset($r['date_end']);

            //set responsible users with their statuses
            $sd['task_u_ongoing'] = array();
            $sd['task_u_done'] = array();

            $res = DB\dbQuery(
                'SELECT user_id, `status`
                FROM tasks_responsible_users
                WHERE task_id = $1',
                $d['id']
            ) or die(DB\dbQueryError());

            while ($r = $res->fetch_assoc()) {
                if ($r['status'] == 1) {
                    $sd['task_u_done'][] = $r['user_id'];
                } else {
                    $sd['task_u_ongoing'][] = $r['user_id'];
                }
            }
            $res->close();
        }
    }

    /**
     * update object
     * @param  array   $p optional properties. If not specified then $this-data is used
     * @return boolean
     */
    public function update($p = false)
    {
        if ($p === false) {
            $p = $this->data;
        }
        $this->data = $p;

        $this->setParamsFromData($p);

        return parent::update($p);
    }

    /**
     * set "sys_data" params from object data
     * @param array &$p
     */
    protected function setParamsFromData(&$p)
    {
        $d = &$p['data'];

        if (empty($d['sys_data'])) {
            $d['sys_data'] = array();
        }

        $sd = &$p['sys_data'];

        $sd['task_due_date'] = $this->getFieldValue('due_date', 0)['value'];
        $sd['task_due_time'] = $this->getFieldValue('due_time', 0)['value'];

        $sd['task_allday'] = empty($sd['task_due_time']);

        //set date_end to be saved in tree table
        if (empty($sd['task_due_date'])) {
            $p['date_end'] = null;
        } else {
            $p['date_end'] = $sd['task_due_date'];
            if (!$sd['task_allday']) {
                $p['date_end'] = substr($sd['task_due_date'], 0, 10)
                    . ' '
                    . $sd['task_due_time'];
            }
        }

        //set assigned users
        if (empty($sd['task_u_done'])) {
            $sd['task_u_done'] = array();
        }

        $assigned = Util\toNumericArray($this->getFieldValue('assigned', 0)['value']);
        $sd['task_u_ongoing'] = array_diff($assigned, $sd['task_u_done']);

        //set status
        $dateEnd = empty($p['date_end'])
            ? null
            : Util\dateISOToMysql($p['date_end']);

        $status = static::$STATUS_ACTIVE; // active

        if (!empty($sd['task_d_closed'])) {
            $status = static::$STATUS_CLOSED; //closed

        } elseif (!empty($dateEnd)) {
            if (strtotime($dateEnd) < strtotime('now')) {
                $status = static::$STATUS_OVERDUE; //overdue
            }
        }

        $sd['task_status'] = $status;
    }

    // Tasks specific methods

    /**
     * mark the task active
     * @return void
     */
    public function markActive()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        unset($sd['task_d_closed']);

        $this->setParamsFromData($d);

    }

    /**
     * mark the task active, reset done user list and update into db
     * @return void
     */
    public function setActive()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markActive();

        unset($this->data['sys_data']['task_u_done']);

        $this->update();
    }

    /**
     * mark the task as closed
     * @return void
     */
    public function markClosed()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $sd['task_status'] = static::$STATUS_CLOSED;
        $sd['task_d_closed'] = date('Y-m-d\TH:i:s\Z');
    }

    /**
     * mark the task as closed and update into db
     * @return void
     */
    public function setClosed()
    {
        if (!$this->loaded) {
            $this->load();
        }

        $this->markClosed();

        $this->update();
    }

    /**
     * simple function to check if task is closed
     * @return boolean
     */
    public function isClosed()
    {
        return ($this->getStatus() == static::$STATUS_CLOSED);
    }

    /**
     * get task status
     * @return int
     */
    public function getStatus()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        $rez = empty($sd['task_status'])
            ? static::$STATUS_NONE
            : $sd['task_status'];

        return $rez;
    }

    /**
     * get user status for loaded task
     * @param int $userId
     */
    public function getUserStatus($userId = false)
    {
        if ($userId == false) {
            $userId = $_SESSION['user']['id'];
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (in_array($userId, $sd['task_u_ongoing'])) {
            return static::$USERSTATUS_ONGOING;
        }

        if (in_array($userId, $sd['task_u_done'])) {
            return static::$USERSTATUS_DONE;
        }

        return static::$USERSTATUS_NONE;
    }

    /**
     * change user status for loaded task
     * @param  array   $p params
     * @return boolean
     */
    public function setUserStatus($status, $userId = false)
    {
        $rez = false;
        if ($userId == false) {
            $userId = $_SESSION['user']['id'];
        }

        $d = &$this->data;
        $sd = &$d['sys_data'];

        switch ($status) {
            case static::$USERSTATUS_ONGOING:
                if (in_array($userId, $sd['task_u_done'])) {
                    $sd['task_u_done'] = array_diff($sd['task_u_done'], array($userId));
                    $sd['task_u_ongoing'][] = $userId;
                    $rez = true;
                }
                break;

            case static::$USERSTATUS_DONE:
                if (in_array($userId, $sd['task_u_ongoing'])) {
                    $sd['task_u_ongoing'] = array_diff($sd['task_u_ongoing'], array($userId));
                    $sd['task_u_done'][] = $userId;
                    $rez = true;
                }
                break;
        }

        if ($rez) {
            $this->checkAutoclose();
        }

        return $rez;
    }

    /**
     * check if a task status should be changed after user status change
     */
    public function checkAutoclose()
    {
        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (empty($sd['task_u_ongoing'])) {
            $this->markClosed();
        } else {
            $this->markActive();
        }
    }

    /**
     *  get action flags that a user can do this task
     * @param  int   $userId
     * @return array
     */
    public function getActionFlags($userId = false)
    {
        $d = &$this->data;

        if ($userId === false) {
            $userId = $_SESSION['user']['id'];
        }

        $isAdmin = \CB\Security::isAdmin($userId);
        $isOwner = $this->isOwner($userId);
        $isClosed = $this->isClosed();
        $canEdit = !$isClosed && ($isAdmin || $isOwner);

        $rez = array(
            'edit' => $canEdit
            ,'close' => $canEdit
            ,'reopen' => ($isClosed && $isOwner)
            ,'complete' => (!$isClosed && ($this->getUserStatus($userId) == static::$USERSTATUS_ONGOING))
        );

        return $rez;
    }

    /**
     * method to get end date for the task
     * @return varchar | null
     */
    public function getEndDate()
    {
        $rez = null;

        $d = &$this->data;
        $sd = &$d['sys_data'];

        if (!empty($sd['task_due_date'])) {
            $rez = $sd['task_due_date'];
            if (!empty($sd['task_due_time'])) {
                $rez = substr($rez, 0, 11) . $sd['task_due_time'] .'Z';
                $rez = Util\userTimeToUTCTimezone($rez);
            }
        }

        return $rez;
    }

    /**
     * get the css class corresponding for status color
     * @param  int     $status
     * @return varchar | null
     */
    public function getStatusCSSColorClass($status = false)
    {
        if ($status === false) {
            $status = $this->getStatus();
        }

        $rez = 'task-status';

        switch ($this->getStatus()) {
            case static::$STATUS_OVERDUE:
                $rez .= ' task-status-overdue';
                break;

            case static::$STATUS_ACTIVE:
                $rez .= ' task-status-active';
                break;

            case static::$STATUS_CLOSED:
                $rez .= ' task-status-closed';
                break;
        }

        return $rez;
    }

    /**
     * generate html preview for a task
     * @param  int   $id task id
     * @return array
     */
    public function getPreviewBlocks()
    {
        $pb = parent::getPreviewBlocks();

        $data = $this->getData();
        $sd = &$data['sys_data'];

        $template = $this->getTemplate();

        $actionsLine = 'Actions<hr />';
        $dateAndStatusLine = '';
        $ownerRow = '';
        $assigneeRow = '';
        $contentRow = '';

        //create actions line
        $flags = $this->getActionFlags();

        $actions = array();

        if (!empty($flags['close'])) {
            $actions[] = '<a action="close" class="task-action ib-done-all">'.L\get('Close').'</a>';
        }
        if (!empty($flags['reopen'])) {
            $actions[] = '<a action="reopen" class="task-action ib-repeat">'.L\get('Reopen').'</a>';
        }
        if (!empty($flags['complete'])) { //empty($flags['close']) &&
            $actions[] = '<a action="complete" class="task-action ib-done">'.L\get('Complete').'</a>';
        }

        $actionsLine = '<div class="task-actions">' . implode(' ', $actions) . '</div>';

        //create date and status row
        $ed = $this->getEndDate();
        $status = $this->getStatus();
        $datePeriod = empty($sd['task_allday'])
            ? Util\formatDateTimePeriod($data['cdate'], $ed, @$_SESSION['user']['cfg']['timezone'])
            : Util\formatDatePeriod($data['cdate'], $ed, @$_SESSION['user']['cfg']['timezone']);

        $dateAndStatusLine = '<div class="task-date-status">' .
            '<div class="date">' .
                $datePeriod .
            '</div>' .
            '<div class="status ' . $this->getStatusCSSColorClass($status) . '">' .
                L\get('taskStatus' . $status) .
            '</div>' .
            '</div>';

        //create owner row
        $v = $this->getOwner();
        if (!empty($v)) {
            $cn = User::getDisplayName($v);
            $cdt = Util\formatAgoTime($data['cdate']);
            $cd = Util\formatDateTimePeriod($data['cdate'], null, @$_SESSION['user']['cfg']['timezone']);

            $ownerRow = '<tr><td class="prop-key">'.L\get('Owner').':</td><td>' .
                '<table class="prop-val people"><tbody>' .
                '<tr><td class="user"><img class="photo32" src="photo/' . $v . '.jpg?32=' . User::getPhotoParam($v) .
                '" style="width:32px; height: 32px" alt="' . $cn . '" title="' . $cn . '"></td>' .
                '<td><b>' . $cn . '</b><p class="gr">'.L\get('Created') . ': ' .
                '<span class="dttm" title="' . $cd . '">' . $cdt . '</span></p></td></tr></tbody></table>' .
                '</td></tr>';
        }

        //create assignee row
        $v = $this->getFieldValue('assigned', 0);

        if (!empty($v['value'])) {

            $isOwner = $this->isOwner();
            $assigneeRow .= '<tr><td class="prop-key">'.L\get('TaskAssigned').':</td><td><table class="prop-val people"><tbody>';
            $v = Util\toNumericArray($v['value']);

            foreach ($v as $id) {
                $un = User::getDisplayName($id);
                $completed = ($this->getUserStatus($id) == static::$USERSTATUS_DONE);
                $flags = $this->getActionFlags($id);

                $assigneeRow .= '<tr><td class="user"><div style="position: relative">'.
                    '<img class="photo32" src="photo/'.$id.'.jpg?32=' . User::getPhotoParam($id).
                    '" style="width:32px; height: 32px" alt="'.$un.'" title="'.$un.'">'.
                ($completed ? '<img class="done icon icon-tick-circle" src="/css/i/s.gif" />': "").
                '</div></td><td><b>'.$un.'</b>'.
                '<p class="gr">'.(
                    $completed
                    ? L\get('Completed'). //.': '.date($date_format.' H:i', strtotime($u['time']))
                        ($isOwner ? ' <a class="bt task-action click" action="markincomplete" uid="'.$id.'">'.L\get('revoke').'</a>' : '')
                    : L\get('waitingForAction').
                        ($isOwner ? ' <a class="bt task-action click" action="markcomplete" uid="'.$id.'">'.L\get('complete').'</a>' : '' )
                ).'</p></td></tr>';
            }

            $assigneeRow .= '</tbody></table></td></tr>';
        }

        //create description row
        $v = $this->getFieldValue('description', 0);
        if (!empty($v['value'])) {
            $tf = $template->getField('description');
            $v = $template->formatValueForDisplay($tf, $v);
            $contentRow = '<tr><td class="prop-val" colspan="2">' . $v . '</td></tr>';
        }

        //insert rows
        $pos = strrpos($pb[0], '</table>');
        if ($pos === false) {
            $pb[0] = '<table class="obj-preview">';
        } else {
            $pb[0] = substr($pb[0], 0, $pos);
        }

        $pb[0] = $actionsLine .
            $dateAndStatusLine .
            $pb[0] .
            $ownerRow .
            $assigneeRow .
            $contentRow .
            '</table>';

        return $pb;
    }
}
