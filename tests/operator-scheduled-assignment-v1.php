<?php
declare(strict_types=1);
require __DIR__.'/phone-contact-management-v1.php';
vp3_operator_admission_install_v1($pdo);
$input=schedule_input($id,['mutation_id'=>'fixture-operator-schedule-001','date'=>gmdate('Y-m-d',time()+6*86400),'time'=>'03:15','recurrence'=>'weekly']);
$series=schedule_save($input)['schedule'];
$eventId=$series['event_id'];
$read=static fn()=>array_column(detail($id,1,['conversation_id'=>11])['schedules']['items'],null,'event_id')[$eventId];
$choice=['contact_id'=>$id,'number_id'=>1,'expected_line'=>'+16025550101','expected_destination'=>detail($id)['contact']['phone_e164'],
    'event_id'=>$eventId,'expected_revision'=>$series['revision'],'enabled'=>true,'assignment_revision'=>0];
c($read()['operator_assignment']==='off','existing reminders default to human calls');
denies(fn()=>action('assign_scheduled_operator',array_replace($choice,['expected_destination'=>'+12025550100'])),422,'unreviewed contact destinations cannot be assigned');
c(action('assign_scheduled_operator',$choice)['assigned']&&$read()['operator_assignment']==='assigned','assignment appears on the original contact schedule');
denies(fn()=>action('assign_scheduled_operator',$choice),409,'a stale review cannot overwrite an assignment');
$pdo->prepare('UPDATE user_calendar_events SET description=? WHERE id=?')->execute(['Changed call objectives',$eventId]);
c($read()['operator_assignment']==='review','a Calendar edit invalidates the previous assignment');
$current=$read();
$remove=array_replace($choice,['assignment_revision'=>$current['assignment_revision'],'expected_revision'=>$current['revision'],'enabled'=>false]);
c(!action('assign_scheduled_operator',$remove)['assigned']&&$read()['operator_assignment']==='off','owner can remove a changed assignment');
echo "PASS scheduled Operator assignment, conflict, review and removal\n";
