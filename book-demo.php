<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/vp3-public.php';
redirect_logged_in_public_page();

$error='';$success=flash('success');
$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$company=trim((string)($_POST['company']??''));$phone=trim((string)($_POST['phone']??''));$notes=trim((string)($_POST['notes']??''));$role=trim((string)($_POST['role']??''));$teamSize=trim((string)($_POST['team_size']??''));
$workflows=array_values(array_filter(array_map('strval',(array)($_POST['workflow']??[]))));
$labels=['assistant'=>'Personal AI assistant','transcription'=>'Transcriptions & AI summaries','knowledge'=>'Second Brain & knowledge','profile'=>'Personal URL & Profile Agent','team'=>'Teams & collaboration','organization'=>'Organization deployment'];
$workflows=array_values(array_intersect($workflows,array_keys($labels)));
$allowedRoles=['personal','creator','professional','manager','team','organization'];if(!in_array($role,$allowedRoles,true))$role='';
$allowedTeamSizes=['1','2-5','6-20','21+'];if(!in_array($teamSize,$allowedTeamSizes,true))$teamSize='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!verify_csrf())$error='Your session expired. Please refresh the page and try again.';
    elseif(trim((string)($_POST['website']??''))!==''){flash('success','Thanks. Your demo request has been received.');redirect(url('/book-demo.php'));}
    elseif($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))$error='Please enter your name and a valid email address.';
    elseif(mb_strlen($name)>120||mb_strlen($email)>190||mb_strlen($company)>190||mb_strlen($phone)>80||mb_strlen($notes)>5000)$error='One or more fields are too long.';
    else{
        $workflowText=$workflows?implode(', ',array_map(static fn(string $item):string=>$labels[$item]??$item,$workflows)):'Not specified';
        $message="VP3 demo request\n\nRole: ".($role!==''?$role:'Not specified')."\nTeam size: ".($teamSize!==''?$teamSize:'Not specified')."\nWorkflows: {$workflowText}\nCompany / organization: ".($company!==''?$company:'Not provided')."\nPhone: ".($phone!==''?$phone:'Not provided')."\n\nRequested demo focus:\n".($notes!==''?$notes:'Not provided');
        $pdo=db();$stored=false;$crmStored=false;$messageId=0;
        if($pdo&&table_exists('contact_messages'))try{
            $stmt=$pdo->prepare('INSERT INTO contact_messages (name,email,topic,message,ip_address,user_agent,created_at) VALUES (?,?,?,?,?,?,NOW())');
            $stmt->execute([$name,$email,'Book a Demo',$message,substr((string)($_SERVER['REMOTE_ADDR']??''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)]);$messageId=(int)$pdo->lastInsertId();$stored=true;
            // Public requests never perform schema DDL. The Admin CRM creates
            // its tables on first admin access and quietly imports requests
            // collected before that point.
            if(function_exists('crm_v180_create_demo_lead')&&function_exists('crm_v180_schema_ready')&&crm_v180_schema_ready($pdo))try{$leadId=crm_v180_create_demo_lead(['name'=>$name,'email'=>$email,'company'=>$company,'phone'=>$phone,'role'=>$role,'team_size'=>$teamSize,'workflows'=>array_map(static fn(string $item):string=>$labels[$item]??$item,$workflows),'notes'=>$notes],$messageId,$pdo);$crmStored=$leadId>0;}catch(Throwable $e){error_log('VP3 CRM lead creation failed: '.$e->getMessage());}
            if (!$crmStored) create_notification_for_permission('messages.manage','contact_message','New demo request',$name.' — Book a Demo',url('/admin/messages.php?view='.$messageId),'contact_message',$messageId);
        }catch(Throwable $e){error_log('VP3 demo request save failed: '.$e->getMessage());}
        $recipient=(string)setting('contact_email',(string)site_config('email',''));$mailed=false;
        if((bool)site_config('send_contact_email',false)&&filter_var($recipient,FILTER_VALIDATE_EMAIL)){$subject='VP3 — Book a Demo';$headers=['From: '.$recipient,'Reply-To: '.$email,'Content-Type: text/plain; charset=UTF-8'];$mailed=@mail($recipient,$subject,"Name: {$name}\nEmail: {$email}\n\n{$message}",implode("\r\n",$headers));}
        if($stored||$mailed){flash('success','Thanks. Your demo request has been received. We will follow up with you soon.');redirect(url('/book-demo.php'));}
        $error='The demo request could not be submitted because the backend is not configured yet. Please use the contact page instead.';
    }
}

vp3_public_header('Book a Demo — VP3','See how VP3 can connect your assistant, transcriptions, knowledge, profile, projects, and team workflows.');
?>
<section class="vp3-public-hero"><div class="vp3-kicker">See VP3 in context</div><h1>Build the assistant around how you work.</h1><p>Tell us what you want to connect and we’ll focus the demo on the parts of VP3 that matter to you.</p></section>
<main><section class="vp3-section"><div class="vp3-wrap vp3-demo-grid">
  <aside class="vp3-card vp3-demo-aside"><div><div class="vp3-kicker">Capture → Understand → Act</div><h2>A connected personal assistant.</h2><p>See how conversations, recordings, summaries, knowledge, profiles, projects, contacts, and teams can work through one assistant instead of separate silos.</p><div class="vp3-about-points"><div class="vp3-about-point"><b>Transcribe and summarize</b><span>Turn recordings into searchable context and action items.</span></div><div class="vp3-about-point"><b>Build a second brain</b><span>Connect notes, files, memories, relationships, and project knowledge.</span></div><div class="vp3-about-point"><b>Use your agent</b><span>Let the main Agent Chat work across approved tools, skills, and proactive opportunities.</span></div></div></div></aside>
  <div class="vp3-card"><div class="vp3-kicker">Request a demo</div><h2>What should we show you?</h2><p>Answer a few questions and we’ll tailor the conversation.</p>
    <?php if($success):?><div class="vp3-alert success"><?=e((string)$success)?></div><?php endif;?><?php if($error):?><div class="vp3-alert error" role="alert"><?=e($error)?></div><?php endif;?>
    <form method="post" action="<?=e(url('/book-demo.php'))?>"><?=csrf_field()?><div style="position:absolute;left:-9999px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
      <div class="vp3-field"><label>What do you want VP3 to help with?</label><div class="vp3-demo-options"><?php foreach($labels as $value=>$label):?><label class="vp3-option"><input type="checkbox" name="workflow[]" value="<?=e($value)?>" <?=in_array($value,$workflows,true)?'checked':''?>> <span><?=e($label)?></span></label><?php endforeach;?></div></div><br>
      <div class="vp3-field"><label>Which best describes you?</label><select name="role"><option value="">Select one</option><?php foreach(['personal'=>'Personal use','creator'=>'Creator','professional'=>'Professional','manager'=>'Manager','team'=>'Team lead','organization'=>'Organization'] as $value=>$label):?><option value="<?=e($value)?>" <?=$role===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><br>
      <div class="vp3-field"><label>How large is the team?</label><select name="team_size"><option value="">Select one</option><?php foreach(['1'=>'Just me','2-5'=>'2–5 people','6-20'=>'6–20 people','21+'=>'21+ people'] as $value=>$label):?><option value="<?=e($value)?>" <?=$teamSize===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></div><br>
      <div class="vp3-form-grid"><div class="vp3-field"><label for="name">Name</label><input id="name" name="name" maxlength="120" autocomplete="name" required value="<?=e($name)?>"></div><div class="vp3-field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="190" autocomplete="email" required value="<?=e($email)?>"></div><div class="vp3-field"><label for="company">Company / organization</label><input id="company" name="company" maxlength="190" value="<?=e($company)?>"></div><div class="vp3-field"><label for="phone">Phone</label><input id="phone" name="phone" maxlength="80" autocomplete="tel" value="<?=e($phone)?>"></div><div class="vp3-field full"><label for="notes">What would you like to see?</label><textarea id="notes" name="notes" maxlength="5000"><?=e($notes)?></textarea></div></div><br><button class="vp3-btn primary" type="submit">Request my demo →</button>
    </form>
  </div>
</div></section></main>
<?php vp3_public_footer(); ?>
