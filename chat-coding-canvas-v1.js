(() => {
  'use strict';
  const cfg = window.VP3_CODING_CANVAS_V1;
  const thread = document.getElementById('chatThread');
  const shell = document.getElementById('chatComposerShell');
  const chatForm = document.getElementById('chatForm');
  const input = document.getElementById('chatInput');
  const owner = Number(window.STONEFELLOW_CHAT?.userId);
  const agent = Number(cfg?.agentId ?? window.STONEFELLOW_CHAT?.agentId ?? 0);
  const pageBinding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '');
  if (!cfg?.endpoint || !cfg?.csrf || !owner || !Number.isSafeInteger(agent) || agent < 0 || !thread || !shell) return;

  const runtime = window.VP3CanvasRuntimeV1;
  if (!runtime) return;
  const {channel,stateLimit,maxText,token,createDocument:previewDocument,sourceKeys,publicSourceKeys,normalizeSource,unavailableSource} = runtime;
  const apiPath = new URL(cfg.endpoint,location.origin).pathname;
  const sourceBasePath = apiPath.includes('/api/') ? apiPath.slice(0,apiPath.lastIndexOf('/api/')) : '';
  const canvasIdPattern = /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/;
  const privateSources = {
    music:'Allow this app to read your VP3 music catalog and listening history',
    calendar:'Allow this app to read your upcoming VP3 calendar events',
    products:'Allow this app to read your VP3 products, including drafts'
  };
  const publicSources = new Set(['public-products','public-events','public-booking','public-campaigns','public-rewards','public-media']);
  const commandBridges = new Set(['app-data','gmail',...Object.keys(privateSources),...publicSources]);
  const mailboxIdPattern = /^[A-Za-z0-9_-]{1,128}$/;
  const publicSlugPattern = /^(?=.{3,64}$)[a-z\d]+(?:-[a-z\d]+)*$/;
  const jobLabels = {queued:'Waiting to build',running:'Working on the canvas',paused:'Build paused',ready:'Draft ready to test',failed:'Coding Agent could not complete this run',stopped:'Build stopped'};
  const previewErrors = {
    source_syntax:'The app code has a JavaScript syntax error.',
    source_startup:'The app code threw an error during startup.',
    source_runtime:'The app code stopped while running.',
    worker_start:'The browser could not start the app runtime.',
    unresponsive:'The app stopped responding.',
    timeout:'The app did not finish starting.',
    render:'The app could not render its content.'
  };
  const activeJob = job => ['queued','running','paused'].includes(job?.state);
  const specialistKey = job => Number.isSafeInteger(job?.run_id) && job.run_id > 0 ? 'coding:' + job.run_id : '';
  const specialistJobs = new Set();
  const chat = () => window.STONEFELLOW_CHAT_CONTINUITY_V87 || window.STONEFELLOW_CHAT_CONTINUITY;
  const scope = () => Number(chat()?.conversationId()) || 0;
  let participantActor = 0;
  const participantContext = () => participantActor > 0 || Boolean(window.VP3ParticipantContinuity?.selectedContext?.());
  const matches = () => Number(chat()?.agentId()) === agent && Number(window.STONEFELLOW_CHAT?.agentId || 0) === agent
    && Number(window.STONEFELLOW_CHAT?.userId) === owner
    && String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '') === pageBinding && !participantContext();
  const shown = () => thread.dataset.view === 'coding';
  const editing = () => shown() && panel.dataset.mode === 'editor';
  function node(tag,text = '',className = '') {
    const el = document.createElement(tag);
    el.textContent = text;
    if (className) el.className = className;
    return el;
  }
  function button(label,handler) {
    const el = node('button',label);
    el.type = 'button';
    el.addEventListener('click',handler);
    return el;
  }

  const panel = node('section','','chat-canvas-view vp3-coding-canvas');
  panel.id = 'chatCodingCanvasV1';
  panel.dataset.chatView = 'coding';
  panel.dataset.mode = 'editor';
  panel.hidden = true;
  const heading = node('h2','Code / App Canvas');
  heading.tabIndex = -1;
  panel.setAttribute('aria-labelledby','chatCodingCanvasTitleV1');
  heading.id = 'chatCodingCanvasTitleV1';
  const header = node('header','','vp3-coding-canvas-header');
  const editor = button('Edit app',() => void openPanel());
  editor.className = 'vp3-coding-canvas-edit-app';
  const close = button('Back to Chat',() => closePanel());
  const headerActions = node('div','','vp3-coding-canvas-controls');
  headerActions.append(editor,close);
  header.append(heading,headerActions);
  const promptLabel = node('label','Describe an app to build or a change to make');
  const prompt = node('textarea');
  prompt.maxLength = 8000;
  prompt.rows = 2;
  prompt.placeholder = 'Build a workshop checklist with reusable tasks…';
  promptLabel.append(prompt);
  const commandLibrary = node('details','','vp3-coding-canvas-commands');
  const commandEntries = node('div');
  const commandLibraryStatus = node('p','Loading Canvas commands…','vp3-coding-canvas-status');
  commandLibraryStatus.setAttribute('role','status');
  commandLibrary.append(node('summary','/commands'),commandEntries,commandLibraryStatus);
  const controls = node('div','','vp3-coding-canvas-controls');
  const build = button('Build new',() => void run('build',prompt.value));
  const emailStart = button('Build from email deadlines',() => void startEmail());
  emailStart.hidden = true;
  const repair = button('Repair',() => void run('repair',prompt.value));
  const stop = button('Stop',() => void stopJob());
  controls.append(build,emailStart,repair,stop);
  const notice = node('p','','vp3-coding-canvas-status');
  notice.setAttribute('role','status');
  const progress = node('section','','vp3-coding-canvas-progress');
  progress.setAttribute('aria-label','Build progress');
  const stages = node('ol','','vp3-coding-canvas-stages');
  stages.setAttribute('aria-label','Canvas build stages');
  const stageItems = ['Build','Test','Use'].map(label => node('li',label));
  stages.append(...stageItems);
  const recoveryDetail = node('p','','vp3-coding-canvas-description');
  const attemptDetail = node('p','','vp3-coding-canvas-description');
  const usageLink = node('a','AI usage');
  if (typeof cfg.usageUrl==='string' && new URL(cfg.usageUrl,location.origin).origin===location.origin) usageLink.href=cfg.usageUrl;
  else usageLink.hidden=true;
  const recoveryActions = node('div','','vp3-coding-canvas-controls');
  const checkStatus = button('Check build status',() => void checkBuildStatus());
  const viewBuild = button('Open build result',() => {
    if (job?.state !== 'ready' || locked || !matches()) return;
    const latest = job;
    openPanel(false);
    void openSaved(latest.canvas_id,latest.revision,false);
  });
  const retryBuild = button('Retry saved request',() => void retrySavedRequest());
  const editRequest = button('Edit saved request',() => void editSavedRequest());
  recoveryActions.append(checkStatus,viewBuild,retryBuild,editRequest);
  progress.append(node('p','Latest build in this Chat','vp3-coding-canvas-description'),stages,notice,attemptDetail,usageLink,recoveryDetail,recoveryActions);
  const appFeedback = node('div','','vp3-coding-canvas-app-feedback');
  const appNotice = node('p');
  appNotice.setAttribute('role','status');
  const appRetry = button('Retry opening app',() => {
    if (appTarget) void openApp(appTarget.id,appTarget.revision);
  });
  appFeedback.hidden = true;
  appFeedback.append(appNotice,appRetry);
  const picker = node('select');
  const pickerLabel = node('label','Saved canvases');
  pickerLabel.append(picker);
  const versions = node('select');
  versions.setAttribute('aria-label','Version');
  const versionsLabel = node('label','Version');
  versionsLabel.append(versions);
  const savedControls = node('div','','vp3-coding-canvas-controls vp3-coding-canvas-pickers');
  savedControls.append(pickerLabel,versionsLabel);
  const editorAppIcons = node('div','','vp3-coding-canvas-app-icons vp3-coding-canvas-editor-apps');
  editorAppIcons.hidden = true;
  editorAppIcons.setAttribute('role','group');
  editorAppIcons.setAttribute('aria-label','Canvas shortcuts');
  const workspace = node('section','','vp3-coding-canvas-workspace');
  const workspaceTitle = node('h3','','vp3-coding-canvas-workspace-title');
  const versionStatus = node('p','','vp3-coding-canvas-version-status');
  const previewDescription = node('p','','vp3-coding-canvas-description');
  const frameHost = node('div','','vp3-coding-canvas-preview');
  const source = node('section','','vp3-coding-canvas-source');
  const sourceLanguage = node('select');
  sourceLanguage.setAttribute('aria-label','Source language');
  for (const [value,label] of [['html','HTML'],['css','CSS'],['js','JavaScript']]) sourceLanguage.append(option(label,value));
  const sourceDescription = node('p','','vp3-coding-canvas-description');
  const code = node('pre');
  source.append(sourceLanguage,sourceDescription,code);
  const sourceEditor = window.VP3CanvasSourceEditorV1?.mount({root:source,code,language:sourceLanguage,
    onChanged:sourceEdited,onSave:saveSourceDraft,onSaved:data=>{
      renderCanvas(data);report('Source draft saved as version '+data.revision.id+'. Test the preview before using it.');
    },onPreview:artifact=>{
      if (!editing() || !matches() || locked || (activeJob(job)&&job.canvas_id===current?.canvas.id)) return;
      setEditorTab('preview');startPreview(false,artifact);updateControls();
    },onAsk:selectSourceContext,onOpenRevision:revision=>{if(matches()&&current?.revision)void openSaved(current.canvas.id,revision);}});
  sourceLanguage.addEventListener('change',() => { if (matches()) renderSource(); });
  const changes = node('section','','vp3-coding-canvas-changes');
  const compareVersion = node('select');
  compareVersion.setAttribute('aria-label','Compare with version');
  const compareLabel = node('label','Compare with version');
  compareLabel.append(compareVersion);
  const comparisonStatus = node('p','','vp3-coding-canvas-description');
  comparisonStatus.setAttribute('role','status');
  const comparisonResult = node('div','','vp3-coding-canvas-diff');
  const compareReload = button('Reload selected version',() => {
    if (current?.revision && !locked && matches()) void openSaved(current.canvas.id,current.revision.id,false);
  });
  compareReload.hidden = true;
  changes.append(compareLabel,comparisonStatus,compareReload,comparisonResult);
  compareVersion.addEventListener('change',() => void loadComparison());
  const viewTabs = node('div','','vp3-coding-canvas-tabs');
  viewTabs.setAttribute('role','tablist');
  viewTabs.setAttribute('aria-label','Canvas views');
  const viewPanels = {preview:frameHost,changes,source};
  const viewButtons = Object.entries({preview:'Preview',changes:'Changes',source:'Source'}).map(([name,label]) => {
    const tab = button(label,() => setEditorTab(name));
    tab.setAttribute('role','tab');
    tab.id = 'canvas-view-'+name+'-v1';
    tab.dataset.canvasView = name;
    tab.setAttribute('aria-controls','canvas-view-panel-'+name+'-v1');
    const body = viewPanels[name];
    body.id = 'canvas-view-panel-'+name+'-v1';
    body.setAttribute('role','tabpanel');
    body.setAttribute('aria-labelledby',tab.id);
    viewTabs.append(tab);
    return tab;
  });
  viewTabs.addEventListener('keydown',event => {
    const index = viewButtons.indexOf(document.activeElement);
    if (index < 0 || !['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
    event.preventDefault();
    const target = event.key === 'Home' ? 0 : event.key === 'End' ? 2 : (index+(event.key === 'ArrowRight' ? 1 : 2))%3;
    setEditorTab(viewButtons[target].dataset.canvasView);
    viewButtons[target].focus();
  });
  const previewCheck = node('section','','vp3-coding-canvas-preview-check');
  const previewCheckText = node('p','','vp3-coding-canvas-description');
  previewCheck.append(node('h3','Preview check'),previewCheckText);
  previewCheck.hidden = true;
  const workflowEditor=window.VP3CanvasWorkflowEditorV1?.mount({root:previewCheck,context:workflowContext,api,
    preview:options=>{setEditorTab('preview');startPreview(false,null,options);return frame;},
    onData:renderCanvas,onJob:next=>advance(next,epoch,scope()),onStop:()=>void stopJob(),onChanged:workflowChanged,
    onReveal:()=>{editorControls.open=true;setEditorTab('preview');}});
  const libraryImagePanel = node('details','','vp3-coding-canvas-commands vp3-coding-canvas-image');
  const libraryInfo = node('p','','vp3-coding-canvas-description');
  const libraryImageStatus = node('p','','vp3-coding-canvas-status');
  libraryImageStatus.setAttribute('role','status');
  const librarySearch = node('input');
  librarySearch.type = 'search';
  librarySearch.maxLength = 190;
  const librarySearchLabel = node('label','Find a Media Library photo');
  librarySearchLabel.append(librarySearch);
  const librarySearchButton = button('Search photos',() => void browseLibraryImages());
  const librarySelect = node('select');
  const libraryLabel = node('label','Saved photo');
  libraryLabel.append(librarySelect);
  const libraryMore = button('More photos',() => void browseLibraryImages(true));
  const libraryPreview = node('img');
  libraryPreview.hidden = true;
  const attachedAction = button('Attach photo',() => void saveLibraryImage(Number(librarySelect.value)));
  const detachAction = button('Remove photo',() => void saveLibraryImage(null));
  const libraryFields = node('div','','vp3-coding-canvas-image-fields');
  const libraryActions = node('div','','vp3-coding-canvas-controls');
  libraryActions.append(attachedAction,detachAction,libraryMore);
  libraryFields.append(librarySearchLabel,librarySearchButton,libraryLabel,libraryPreview,libraryActions);
  libraryImagePanel.append(node('summary','App image'),libraryInfo,libraryFields,libraryImageStatus);
  librarySelect.addEventListener('change',updateLibraryImageControls);
  librarySearch.addEventListener('keydown',event => {
    if (event.key === 'Enter') { event.preventDefault(); void browseLibraryImages(); }
  });
  libraryImagePanel.addEventListener('toggle',() => {
    if (libraryImagePanel.open && !libraryRows.size && !libraryRead) void browseLibraryImages();
  });
  const permissions = node('details','','vp3-coding-canvas-commands');
  permissions.append(node('summary','Data permissions'));
  permissions.addEventListener('toggle',() => { if (permissions.open && !commandSettings) void loadCommandSettings(); });
  const permissionFields = node('div','','vp3-coding-canvas-permission-fields');
  const appAccess = node('input');
  appAccess.type = 'checkbox';
  const specialistReview = node('input');
  specialistReview.type = 'checkbox';
  const gmailAccess = node('input');
  gmailAccess.type = 'checkbox';
  const gmailMode = node('select');
  gmailMode.append(option('Saved Gmail search','search'),option('Selected Gmail threads','threads'));
  const gmailQuery = node('input');
  gmailQuery.type = 'text';
  gmailQuery.maxLength = 256;
  gmailQuery.placeholder = 'in:inbox label:projects';
  const gmailThreadIds = node('textarea');
  gmailThreadIds.rows = 3;
  gmailThreadIds.maxLength = 2048;
  const permissionLabel = (label,control,check = false) => {
    const element = node('label',label,check ? 'vp3-coding-canvas-checkbox' : '');
    if (check) element.prepend(control);
    else element.append(control);
    return element;
  };
  const gmailScope = node('div','','vp3-coding-canvas-permission-fields');
  const gmailQueryLabel = permissionLabel('Gmail search',gmailQuery);
  const gmailThreadsLabel = permissionLabel('Gmail thread IDs or links, one per line',gmailThreadIds);
  gmailScope.append(permissionLabel('Approved Gmail scope',gmailMode),gmailQueryLabel,gmailThreadsLabel);
  const savePermissions = button('Save data permissions',() => void saveCommandSettings());
  const permissionStatus = node('p','','vp3-coding-canvas-description');
  permissionStatus.setAttribute('aria-live','polite');
  const privateSourceControls = Object.entries(privateSources).map(([name,description]) => {
    const control = node('input');
    control.type = 'checkbox';
    const label = permissionLabel(description,control,true);
    return {name,control,label};
  });
  permissionFields.append(permissionLabel('Allow this app to read and save its own records',appAccess,true),
    permissionLabel('Let the Canvas Apps specialist review this app’s records and suggest follow-ups',specialistReview,true),
    permissionLabel('Allow this app to read approved Gmail results',gmailAccess,true),gmailScope,
    ...privateSourceControls.map(({label}) => label),savePermissions);
  permissionFields.hidden = true;
  gmailAccess.addEventListener('change',updateGmailScope);
  gmailMode.addEventListener('change',updateGmailScope);
  permissions.append(permissionFields,permissionStatus);
  const publishing = node('details','','vp3-coding-canvas-commands vp3-coding-canvas-publishing');
  const publishingSummary = node('summary','Public publishing');
  const publishingBody = node('div','','vp3-coding-canvas-publishing-body');
  const publishingState = node('p');
  const publishingLink = node('a');
  publishingLink.target = '_blank';
  publishingLink.rel = 'noopener noreferrer';
  const publishingNote = node('p','','vp3-coding-canvas-description');
  const publishingFields = node('div','','vp3-coding-canvas-publishing-fields');
  const publishingSlug = node('input');
  publishingSlug.type = 'text';
  publishingSlug.maxLength = 64;
  publishingSlug.autocapitalize = 'none';
  publishingSlug.autocomplete = 'off';
  publishingSlug.spellcheck = false;
  const publishingSlugLabel = permissionLabel('Public link slug',publishingSlug);
  const publish = button('Publish version…',() => confirmPublication('publish'));
  const unpublish = button('Unpublish…',() => confirmPublication('unpublish'));
  const publicationActions = node('div','','vp3-coding-canvas-controls');
  publicationActions.append(publish,unpublish);
  const publishingConfirmation = node('div','','vp3-coding-canvas-publishing-confirmation');
  const publishingConsent = node('p');
  const confirmPublishing = button('Confirm',() => void commitPublication());
  const cancelPublishing = button('Cancel',() => cancelPublication(true));
  const confirmationActions = node('div','','vp3-coding-canvas-controls');
  confirmationActions.append(confirmPublishing,cancelPublishing);
  publishingConfirmation.append(publishingConsent,confirmationActions);
  publishingFields.append(publishingSlugLabel,publicationActions,publishingConfirmation);
  const publicationStatus = node('p','','vp3-coding-canvas-status');
  publicationStatus.setAttribute('role','status');
  const retryPublication = button('Reload publishing status',() => void loadPublication());
  const profileCanvasPanel = node('div','','vp3-coding-canvas-profile');
  const profileCanvasCheck = node('input');
  profileCanvasCheck.type = 'checkbox';
  profileCanvasCheck.disabled = true;
  const profileCanvasLabel = permissionLabel('Show on my Profile',profileCanvasCheck,true);
  const profileCanvasDescription = node('p','Visitors open this Canvas as a full page with your Profile Agent chat.','vp3-coding-canvas-description');
  const profileCanvasState = node('p','','vp3-coding-canvas-description');
  const profileCanvasLink = node('a','Open full-page Profile Canvas');
  profileCanvasLink.target = '_blank';
  profileCanvasLink.rel = 'noopener noreferrer';
  profileCanvasLink.hidden = true;
  const saveProfileCanvas = button('Save Profile visibility',() => void commitProfileCanvas());
  const profileCanvasStatus = node('p','','vp3-coding-canvas-status');
  profileCanvasStatus.setAttribute('role','status');
  const retryProfileCanvas = button('Reload Profile visibility',() => void loadProfileCanvas());
  retryProfileCanvas.hidden = true;
  profileCanvasCheck.addEventListener('change',updateProfileCanvasControls);
  profileCanvasPanel.append(profileCanvasLabel,profileCanvasDescription,profileCanvasState,profileCanvasLink,
    saveProfileCanvas,profileCanvasStatus,retryProfileCanvas);
  publishingBody.append(publishingState,publishingLink,publishingNote,publishingFields,publicationStatus,retryPublication,profileCanvasPanel);
  publishing.append(publishingSummary,publishingBody);
  publishing.addEventListener('toggle',() => {
    if (publishing.open && !publication && !publicationRequest) void loadPublication();
    else if (!publishing.open) cancelPublication(false);
  });
  const actions = node('div','','vp3-coding-canvas-controls');
  const openInApp = button('Open app',() => {
    if (current?.revision) void openApp(current.canvas.id,current.revision.id);
  });
  const test = button('Test preview',() => void testPreview());
  const testAndRepair=button('Test & repair',()=>void workflowEditor?.start(true));
  const apply = button('Use this version',() => void applyVersion());
  const hideApp = button('Hide app from shortcuts',() => void setAppHidden());
  const remove = button('Delete canvas…',() => showDelete(true));
  const sendPhone=button('Send Canvas to Connected Phone',event=>{if(event.isTrusted)void openPhoneDelivery();});
  const phoneNotice=node('p','','vp3-coding-canvas-phone-status');phoneNotice.setAttribute('role','status');
  const phoneDialog=node('dialog','','vp3-coding-canvas-phone-dialog');phoneDialog.setAttribute('aria-label','Send Canvas to Connected Phone');
  const phoneDescription=node('p'),phoneStatus=node('p');phoneStatus.setAttribute('role','status');
  const phoneSelect=node('select');phoneSelect.setAttribute('aria-label','Connected phone');
  const phoneAgreement=node('input');phoneAgreement.type='checkbox';
  const phoneAgreementLabel=node('label');phoneAgreementLabel.append(phoneAgreement,node('span','Allow this phone’s AGENT tab to receive private email reviews, canvases and results from this Agent without logging in.'));
  const sendPhoneConfirm=button('Send Canvas',event=>{if(event.isTrusted)void deliverPhoneCanvas();});
  const phoneCancel=button('Cancel',()=>closePhoneDelivery());
  phoneDialog.append(node('h2','Send Canvas to Connected Phone'),phoneDescription,phoneSelect,phoneAgreementLabel,
    node('p','The saved Canvas appears in AGENT. Alerts follow this phone’s notification settings.'),phoneStatus,sendPhoneConfirm,phoneCancel);
  document.body.append(phoneDialog);
  let phoneCapture=null,phoneController=null,phoneTargets=[],phoneWriting=false,phoneIntent=null;
  function phoneCurrent(capture){return capture&&capture.epoch===epoch&&matches()&&scope()===capture.conversation&&current?.canvas.id===capture.canvas&&current?.revision?.id===capture.revision;}
  function closePhoneDelivery(){phoneController?.abort();phoneController=null;phoneCapture=null;phoneTargets=[];phoneWriting=false;phoneIntent=null;if(phoneDialog.open)phoneDialog.close();}
  phoneDialog.addEventListener('cancel',()=>closePhoneDelivery());
  phoneSelect.addEventListener('change',()=>{phoneIntent=null;const p=phoneTargets.find(p=>p.id===phoneSelect.value);phoneAgreement.checked=p?.agent_access?.enabled===true&&p.agent_access.agent_id===agent;phoneControls();});
  phoneAgreement.addEventListener('change',()=>{phoneControls();});
  function phoneControls(){sendPhoneConfirm.disabled=phoneWriting||!phoneCurrent(phoneCapture)||!phoneAgreement.checked||!phoneTargets.some(p=>p.id===phoneSelect.value);phoneSelect.disabled=phoneAgreement.disabled=phoneWriting;}
  async function openPhoneDelivery(){
    if(locked||!current?.revision||!matches())return;
    closePhoneDelivery();const capture={epoch,canvas:current.canvas.id,revision:current.revision.id,conversation:scope()};phoneCapture=capture;
    phoneDescription.textContent='Saved Canvas version '+capture.revision;phoneStatus.textContent='Loading connected phones…';phoneSelect.replaceChildren();phoneAgreement.checked=false;phoneControls();phoneDialog.showModal();
    const controller=new AbortController();phoneController=controller;
    try{
      const result=await api('phone_targets',{canvas_id:capture.canvas,revision:capture.revision},capture.conversation,controller.signal);
      if(phoneController!==controller||!phoneCurrent(capture)||!phoneDialog.open)return;
      if(!Array.isArray(result.phones)||result.phones.length>32||result.canvas_id!==capture.canvas||result.revision!==capture.revision)throw Error('Connected phones could not be verified.');
      phoneTargets=result.phones.filter(p=>typeof p.id==='string'&&/^[a-f0-9]{32}$/.test(p.id)&&typeof p.name==='string'&&Number.isSafeInteger(p.generation)&&Number.isSafeInteger(p.agent_access?.revision));
      for(const p of phoneTargets){const option=node('option',p.name);option.value=p.id;phoneSelect.append(option);}
      const selectedPhone=phoneTargets[0];phoneAgreement.checked=selectedPhone?.agent_access?.enabled===true&&selectedPhone.agent_access.agent_id===agent;
      phoneStatus.textContent=phoneTargets.length?'Choose the phone that should receive this Canvas.':'No approved phones are connected. Connect and approve a phone in Phone Tracking first.';
      phoneDescription.textContent='Saved Canvas version '+capture.revision+' · '+(result.agent?.name||'this Agent');
    }catch(error){if(phoneController===controller&&phoneDialog.open&&error.name!=='AbortError')phoneStatus.textContent=error.message||'Could not load connected phones.';}
    finally{if(phoneController===controller){phoneController=null;phoneControls();}}
  }
  async function deliverPhoneCanvas(){
    const capture=phoneCapture,target=phoneTargets.find(p=>p.id===phoneSelect.value);
    if(!phoneCurrent(capture)||!target||!phoneAgreement.checked||phoneWriting)return;
    phoneWriting=true;phoneControls();phoneStatus.textContent='Sending saved Canvas…';phoneIntent=phoneIntent||crypto.randomUUID();
    const controller=new AbortController();phoneController=controller;
    try{
      const result=await api('phone_send',{canvas_id:capture.canvas,revision:capture.revision,device_id:target.id,generation:target.generation,expected_revision:target.agent_access.revision,request_id:phoneIntent,confirmed:true},capture.conversation,controller.signal);
      if(phoneController!==controller||!phoneCurrent(capture)||!phoneDialog.open)return;
      const receipt=result.phone_delivery;
      if(receipt?.device_id!==target.id||receipt.revision!==capture.revision||typeof receipt.item_ref!=='string'||!/^[a-f0-9]{32}$/.test(receipt.item_ref))throw Error('The phone delivery could not be verified.');
      phoneNotice.textContent='Canvas version '+capture.revision+' is available in AGENT on '+target.name+'.';closePhoneDelivery();
    }catch(error){if(phoneController===controller&&phoneDialog.open&&error.name!=='AbortError')phoneStatus.textContent=error.message||'Delivery could not be confirmed. Retry to check the same delivery.';}
    finally{if(phoneController===controller){phoneController=null;phoneWriting=false;phoneControls();}}
  }
  const viewWorking = button('View working version',() => {
    if (!locked && matches() && current?.canvas.active_revision) void openSaved(current.canvas.id,current.canvas.active_revision);
  });
  actions.append(test,testAndRepair,apply,viewWorking,openInApp,sendPhone,hideApp,remove);

  const deletion = node('div','','vp3-coding-canvas-controls');
  const deleteFolder = node('input');
  deleteFolder.type = 'checkbox';
  const deleteLabel = node('label',' Also delete its Knowledge Base folder and data');
  deleteLabel.prepend(deleteFolder);
  const deleteConfirm = button('Delete canvas',() => void deleteCanvas());
  deletion.append(node('p','Deleting this Canvas takes any public link offline.','vp3-coding-canvas-delete-public-note'),
    deleteLabel,deleteConfirm,button('Cancel',() => showDelete(false)));
  deletion.hidden = true;
  const appSettings = node('div','','vp3-coding-canvas-app-settings');
  appSettings.append(libraryImagePanel,permissions,publishing);
  workspace.append(workspaceTitle,versionStatus,previewDescription,actions,phoneNotice,deletion,appSettings,viewTabs,frameHost,changes,source);
  workspace.hidden = true;
  const editorControls = node('details','','vp3-coding-canvas-editor-controls');
  editorControls.open = true;
  const requestSummary = node('summary','Instructions & checks');
  const editorControlBody = node('div','','vp3-coding-canvas-editor-body');
  const sourceContext = node('div','','vp3-coding-source-context');sourceContext.hidden=true;
  const sourceContextLabel = node('p','','vp3-coding-canvas-description');
  const sourceMakeChange = button('Make requested change',()=>void run('repair',prompt.value));
  sourceContext.append(sourceContextLabel,sourceMakeChange,button('Clear code selection',()=>{clearSourceContext();updateControls();}));
  editorControlBody.append(promptLabel,sourceContext,commandLibrary,controls,progress,previewCheck);
  prompt.addEventListener('input',()=>updateControls());
  editorControls.append(requestSummary,editorControlBody);
  const editorEmpty = node('section','','vp3-coding-canvas-empty');
  editorEmpty.append(node('h3','Build your next Canvas'),node('p','Describe an app or workflow, then choose Build new. Your draft will appear here for testing and review.'));
  const editorLayout = node('div','','vp3-coding-canvas-editor-layout');
  const liveSourceHost=node('section');liveSourceHost.hidden=true;
  const liveSource=window.VP3CanvasLiveSourceV1?.mount({root:liveSourceHost,
    fetchSource:(runId,signal)=>api('received_source',{run_id:runId},scope(),signal),
    recoverSource:async(runId,requestId)=>{
      if(locked||sourceEditor?.dirty()||sourceEditor?.pending()||prompt.value.trim())throw Error('Save your source edits and keep or clear your unsent instruction before importing received code.');
      const version=epoch,selection=load,selected=current;
      locked=true;updateControls();
      try{
        const data=await api('recover_source',{run_id:runId,request_id:requestId});
        return {...data,adoptReceived:version===epoch&&selection===load&&selected===current};
      }finally{if(version===epoch){locked=false;updateControls();}}
    },onRecovered:data=>{
      if(!matches()||!editing())return;
      if(data.adoptReceived===false){report('Received code was saved as a draft. Open Saved canvases to review it.');return;}
      renderCanvas(data);report('Received code saved as version '+data.revision.id+'. Test the preview before using it.');
    },onError:message=>report(message)});
  const editorContent=node('div','','vp3-coding-canvas-editor-content');
  editorContent.append(liveSourceHost,editorEmpty,workspace);
  editorLayout.append(editorControls,editorContent);
  panel.append(header,savedControls,editorAppIcons,appFeedback,editorLayout);
  thread.append(panel);

  const dock = node('div','','vp3-coding-canvas-dock');
  const shortcuts = node('div','','vp3-coding-canvas-shortcuts');
  const launch = button('Code / App Canvas',() => void openPanel());
  launch.setAttribute('aria-controls',panel.id);
  launch.setAttribute('aria-expanded','false');
  const appIcons = node('div','','vp3-coding-canvas-app-icons');
  appIcons.hidden = true;
  appIcons.setAttribute('role','group');
  appIcons.setAttribute('aria-label','Saved Canvas apps');
  shortcuts.append(launch,appIcons);
  const worker = button('Coding Agent · Working on the canvas',() => window.VP3ChatSpecialists?.open(specialistKey(job),worker));
  worker.className = 'vp3-coding-canvas-worker';
  worker.setAttribute('aria-expanded','false');
  worker.setAttribute('aria-haspopup','dialog');
  worker.hidden = true;
  const dockStatus = node('span','','vp3-coding-canvas-dock-status');
  dockStatus.setAttribute('aria-live','polite');
  dock.append(shortcuts,worker,dockStatus);
  shell.append(dock);
  window.addEventListener('vp3:chat-specialists-changed',() => {
    const key = specialistKey(job);
    worker.hidden = job?.state !== 'running' || !window.VP3ChatSpecialists?.snapshot().some(entry => entry.key === key);
    if (worker.hidden) worker.removeAttribute('aria-controls');
    else worker.setAttribute('aria-controls','vp3-chat-specialist-panel-' + key);
  });

  let epoch = 0;
  let load = 0;
  let openController = null;
  let appTarget = null;
  let chatPosition = null;
  let listRequest = 0;
  let editorListRequest = 0;
  let renderedAppIcons = null;
  let commandCatalog;
  let commandCatalogRequest = 0;
  let footerTimer = 0;
  let current = null;
  let selectedSourceContext = null;
  let editorTab = 'preview';
  let comparisonRead = null;
  let frame = null;
  let artworkFrame = null;
  const artworkAttempts = new Set();
  let job = null;
  let observedJob = null;
  let poll = 0;
  let resumePoll = 0;
  let resumeTimer = 0;
  let resuming = false;
  let resumeAgain = false;
  let suspended = false;
  let locked = false;
  let pollingError = false;
  let pollingBlocked = false;
  let browserTest = null;
  let lastIntent = null;
  let checkingBuild = false;
  let lastBuildCheck = 0;
  let lastEmailStart = null;
  let failedPreview = null;
  let ticked = null;
  const sourceRefreshes = new Map();
  let sourceUpdateTimer = 0;
  let commandSettings = null;
  let commandSettingsRequest = null;
  let publication = null;
  let publicationRequest = null;
  let publicationIntent = null;
  let profileCanvas = null;
  let profileCanvasPublication = null;
  let profileCanvasRequest = null;
  let libraryRead = null;
  let libraryWrite = null;
  let libraryCursor = null;
  let libraryQuery = '';
  const libraryRows = new Map();
  const emailUnavailable = {generation:0,rows:[]};
  const emailStages = new Set(['needs_review','awaiting_reply','reply_received','followup_due','actioned']);
  const emailFixtureSubjects = ['Sample Elm deadline','Sample Birch deadline','Sample old Pine deadline'];
  const gmailUnavailable = {status:'unavailable',rows:[]};
  const commandChangesKey = 'vp3-canvas-data-permissions';

  function emailFixture() {
    const date = days => new Date(Date.now() + days * 86400000).toISOString().slice(0,10);
    const rows = [1,2,0].map(index => ({id:`sample-${index}`,subject:emailFixtureSubjects[index],
      stage:'needs_review',due_date:date([1,2,-2][index]),due_at:null,deadline_current:index !== 2}));
    return {status:'ready',rows};
  }
  function gmailFixture() {
    const date = new Date().toISOString();
    return {status:'ready',rows:[
      {id:'sample-gmail-1',subject:'Sample planning update',sender:'Alex <alex@example.test>',date,snippet:'Here is the latest outline for the project.'},
      {id:'sample-gmail-2',subject:'Sample workshop schedule',sender:'Jo <jo@example.test>',date,snippet:'Please review the sample agenda before Friday.'}
    ]};
  }
  const declaredCommands = () => current?.revision?.commands;
  const declaresGmail = () => declaredCommands()?.includes('gmail') === true;

  function normalizeEmail(value) {
    if (value?.workflow !== 'deadline_board' || !Number.isSafeInteger(value.generation) || value.generation < 1 ||
      !Array.isArray(value.rows) || value.rows.length > 50) return emailUnavailable;
    const ids = new Set();
    const rows = [];
    for (const row of value.rows) {
      if (!/^[a-f0-9]{64}$/.test(row?.id ?? '') || ids.has(row.id) || !/^[a-f0-9]{64}$/.test(row.source_ref ?? '') ||
        typeof row.subject !== 'string' || row.subject.length > 240 || !emailStages.has(row.stage) ||
        !/^\d{4}-\d\d-\d\d$/.test(row.due_date ?? '') ||
        (row.due_at !== null && (typeof row.due_at !== 'string' || !Number.isFinite(Date.parse(row.due_at)))) ||
        typeof row.deadline_current !== 'boolean') return emailUnavailable;
      ids.add(row.id);
      rows.push({id:row.id,subject:row.subject,stage:row.stage,due_date:row.due_date,due_at:row.due_at,
        deadline_current:row.deadline_current,source_ref:row.source_ref});
    }
    return {generation:value.generation,rows};
  }

  function normalizeGmail(value) {
    if (value?.status !== 'ready' || !Array.isArray(value.rows) || value.rows.length > 12) return gmailUnavailable;
    const rows = [];
    const bounded = (text,limit) => typeof text === 'string' && text.length <= limit * 2 && Array.from(text).length <= limit;
    for (const row of value.rows) {
      if (typeof row?.id !== 'string' || !mailboxIdPattern.test(row.id) || !bounded(row.subject,250) ||
        !bounded(row.sender,250) || !bounded(row.date,96) || !bounded(row.snippet,400)) return gmailUnavailable;
      rows.push({id:row.id,subject:row.subject,sender:row.sender,date:row.date,snippet:row.snippet});
    }
    return {status:'ready',rows};
  }

  function stopSourceRefreshes() {
    clearTimeout(sourceUpdateTimer);
    sourceUpdateTimer = 0;
    for (const refresh of sourceRefreshes.values()) {
      clearTimeout(refresh.timer);
      refresh.controller?.abort();
    }
    sourceRefreshes.clear();
  }

  const unavailableSnapshot = kind => kind === 'email' ? emailUnavailable : kind === 'gmail' ? gmailUnavailable :
    {payload:unavailableSource,links:[]};

  async function readSourceRefresh(refresh) {
    if (sourceRefreshes.get(refresh.kind) !== refresh || document.hidden || !shown() || !matches()) return;
    const controller = new AbortController();
    refresh.controller = controller;
    const isGmail = refresh.kind === 'gmail';
    const isEmail = refresh.kind === 'email';
    const deadline = setTimeout(() => controller.abort(),isGmail ? 20000 : 7000);
    let snapshot = unavailableSnapshot(refresh.kind);
    try {
      const fields = {canvas_id:refresh.canvasId,revision:refresh.revision};
      if (!isEmail) fields.command = refresh.kind;
      const data = await api(isEmail ? 'email_data' : 'command_data',fields,refresh.conversation,controller.signal);
      snapshot = isEmail ? normalizeEmail(data.email) : isGmail ? normalizeGmail(data.source) :
        normalizeSource(refresh.kind,data.source,sourceBasePath);
    } catch (_error) {
      // Clear stale results if authorization or the data read fails.
    } finally {
      clearTimeout(deadline);
    }
    if (sourceRefreshes.get(refresh.kind) !== refresh || refresh.epoch !== epoch || refresh.conversation !== scope() ||
      document.hidden || !shown() || !matches()) return;
    refresh.controller = null;
    const changed = JSON.stringify(refresh.snapshot) !== JSON.stringify(snapshot);
    refresh.snapshot = snapshot;
    if (changed) {
      clearTimeout(sourceUpdateTimer);
      sourceUpdateTimer = setTimeout(() => {
        if (!frame?.testing && !document.hidden && shown() && matches() && (frame || !previewFailed())) startPreview();
      },80);
    }
    refresh.timer = setTimeout(() => void readSourceRefresh(refresh),isEmail ? 15000 : 60000);
  }

  function ensureSourceRefresh(kind) {
    let refresh = sourceRefreshes.get(kind);
    if (!refresh) {
      refresh = {kind,canvasId:current.canvas.id,revision:current.revision.id,epoch,conversation:scope(),
        snapshot:unavailableSnapshot(kind)};
      sourceRefreshes.set(kind,refresh);
      void readSourceRefresh(refresh);
    }
    return refresh.snapshot;
  }

  function previewSources(commands,isActive) {
    const sources = {};
    const links = {};
    for (const command of Array.isArray(commands) ? commands : []) {
      if (!Object.hasOwn(sourceKeys,command)) continue;
      const key = sourceKeys[command];
      const isPublic = publicSourceKeys.includes(key);
      const allowed = isActive && (isPublic || commandSettings?.sources?.[command] === true);
      const snapshot = allowed ? ensureSourceRefresh(command) : unavailableSnapshot(command);
      sources[key] = snapshot.payload;
      if (isPublic) links[key] = snapshot.links;
    }
    return {sources,links};
  }

  function renderCommandCatalog(value) {
    const entries = Array.isArray(value) ? new Map() : null;
    for (const item of entries ? value : []) {
      const name = typeof item?.command === 'string' && item.command.startsWith('/') && item.command.slice(1);
      if (!commandBridges.has(name) || entries.has(name) || typeof item.description !== 'string' ||
        !item.description.trim() || item.description.length > 300) continue;
      const visibility = publicSources.has(name) ? 'public' : 'private';
      if (item.visibility !== visibility && !(item.visibility === undefined && ['app-data','gmail'].includes(name))) continue;
      entries.set(name,{description:item.description,visibility});
    }
    const catalog = entries ? [...entries] : null;
    if (JSON.stringify(commandCatalog) === JSON.stringify(catalog)) return;
    commandCatalog = catalog;
    commandEntries.replaceChildren();
    for (const [visibility,heading] of [['private','Private data'],['public','Public data']]) {
      const commands = (catalog ?? []).filter(([,entry]) => entry.visibility === visibility);
      if (!commands.length) continue;
      const group = node('section','','vp3-coding-canvas-command-group');
      group.append(node('h3',heading));
      for (const [name,{description}] of commands) {
        const row = node('div','','vp3-coding-canvas-command');
        row.append(button('/'+name,() => insertCommand(name)),node('p',description));
        group.append(row);
      }
      commandEntries.append(group);
    }
    commandLibraryStatus.hidden = Boolean(catalog?.length);
    commandLibraryStatus.textContent = catalog ? 'No Canvas commands are available.' : 'Canvas commands could not be loaded.';
  }
  function promptCommands(text) {
    const tokens = Array.from(text.matchAll(/(?:^|\s)\/([a-z][a-z\d-]*)(?=$|[\s.,!?;:])/gi),match => match[1].toLowerCase());
    return new Set(tokens.filter(command => commandBridges.has(command)));
  }
  function insertCommand(command) {
    if (!promptCommands(prompt.value).has(command)) {
      const text = prompt.value.trimEnd();
      prompt.value = (text ? text + '\n' : '') + '/' + command + ' ';
    }
    prompt.focus();
    prompt.setSelectionRange(prompt.value.length,prompt.value.length);
  }
  function openCommandLibrary() {
    if (!matches() || !chat()?.isReady() || chat()?.isRestoring()) return;
    openPanel();
    commandLibrary.open = true;
    commandLibrary.querySelector('summary')?.focus();
  }
  function updateGmailScope() {
    gmailScope.hidden = !gmailAccess.checked;
    gmailQueryLabel.hidden = gmailMode.value !== 'search';
    gmailThreadsLabel.hidden = gmailMode.value !== 'threads';
  }
  function parseGmailThreadId(value) {
    if (mailboxIdPattern.test(value)) return value;
    try {
      const url = new URL(value);
      if (url.origin === 'https://mail.google.com' && !url.username && !url.password && url.pathname.startsWith('/mail/')) {
        const id = url.hash.split('/').at(-1);
        if (mailboxIdPattern.test(id)) return id;
      }
    } catch (_error) { /* Leave invalid entries for form validation. */ }
    return '';
  }
  function normalizeCommandSettings(value) {
    if (!/^[a-f0-9]{64}$/.test(value?.revision ?? '') ||
      ![value.app_data?.app_access,value.app_data?.specialist_review,value.gmail?.app_access,value.gmail?.specialist_review]
        .every(setting => typeof setting === 'boolean')) return null;
    const scope = value.gmail.scope;
    const validScope = scope?.mode === 'search' && typeof scope.query === 'string' && scope.query.length <= 256 ||
      scope?.mode === 'threads' && Array.isArray(scope.thread_ids) && scope.thread_ids.length <= 12 &&
      scope.thread_ids.every(id => typeof id === 'string' && mailboxIdPattern.test(id));
    if (value.gmail.app_access && !validScope) return null;
    const sourceNames = Object.keys(privateSources);
    const inputSources = value.sources;
    if (inputSources !== undefined && (!inputSources || typeof inputSources !== 'object' || Array.isArray(inputSources) ||
      Object.keys(inputSources).length !== sourceNames.length || !sourceNames.every(name => typeof inputSources[name] === 'boolean'))) return null;
    const sources = Object.fromEntries(sourceNames.map(name => [name,inputSources?.[name] === true]));
    return {revision:value.revision,app_data:{app_access:value.app_data.app_access,specialist_review:value.app_data.specialist_review},
      gmail:{app_access:value.gmail.app_access,specialist_review:value.gmail.specialist_review,scope:validScope ? scope : null},sources};
  }
  function setCommandSettings(value) {
    commandSettings = value;
    if (value && current) current.hasVerifiedGrants = true;
    appAccess.checked = value?.app_data.app_access === true;
    specialistReview.checked = value?.app_data.specialist_review === true;
    gmailAccess.checked = value?.gmail.app_access === true;
    gmailMode.value = value?.gmail.scope?.mode ?? 'search';
    gmailQuery.value = value?.gmail.scope?.query ?? '';
    gmailThreadIds.value = value?.gmail.scope?.thread_ids?.join('\n') ?? '';
    for (const {name,control,label} of privateSourceControls) {
      label.hidden = !declaredCommands()?.includes(name);
      control.checked = value?.sources[name] === true;
    }
    updateGmailScope();
    permissionFields.hidden = !value;
  }
  function clearPrivateAppState(selected) {
    if (!selected?.state) return;
    selected.state = {...selected.state,value:{}};
    selected.commandStateWithheld = true;
  }
  async function restoreAppState(selected,settings,conversation,signal) {
    if (!settings.app_data.app_access) {
      clearPrivateAppState(selected);
      return;
    }
    if (!selected.commandStateWithheld) return;
    const data = await api('open',{canvas_id:selected.canvas.id,revision:selected.revision.id},conversation,signal);
    if (signal?.aborted || selected !== current || conversation !== scope() || !matches()) return;
    if (data.canvas?.id !== selected.canvas.id || data.revision?.id !== selected.revision.id || !data.state ||
      normalizeCommandSettings(data.command_grants)?.revision !== settings.revision) {
      throw new Error('App data permissions changed. Reopen the Canvas to verify access.');
    }
    selected.state = data.state;
    selected.commandStateWithheld = false;
  }
  async function loadCommandSettings() {
    const selected = current;
    const conversation = scope();
    if (!selected?.revision || !shown() || !matches() || document.hidden) return;
    commandSettingsRequest?.abort();
    const controller = new AbortController();
    commandSettingsRequest = controller;
    const stillSelected = () => !controller.signal.aborted && selected === current && conversation === scope() && shown() && matches();
    if (!commandSettings) permissionStatus.textContent = 'Loading data permissions…';
    try {
      const data = await api('command_grants',{canvas_id:selected.canvas.id,revision:selected.revision.id},conversation,controller.signal);
      if (!stillSelected()) return;
      const settings = normalizeCommandSettings(data.commands);
      if (!settings) throw new Error('Data permissions could not be verified.');
      permissionStatus.textContent = '';
      if (settings.revision !== commandSettings?.revision || !frame) {
        await restoreAppState(selected,settings,conversation,controller.signal);
        if (!stillSelected()) return;
        setCommandSettings(settings);
        stopSourceRefreshes();
        startPreview();
      }
    } catch (error) {
      if (!stillSelected()) return;
      permissionStatus.textContent = error.message;
      if (commandSettings) {
        clearPrivateAppState(selected);
        setCommandSettings(null);
      }
      stopSourceRefreshes();
      startPreview();
    } finally {
      if (commandSettingsRequest === controller) commandSettingsRequest = null;
    }
  }
  async function saveCommandSettings() {
    const selected = current;
    const conversation = scope();
    const baseline = commandSettings;
    if (!baseline || !selected?.revision || savePermissions.disabled || !matches() || !editing()) return;
    let gmail = null;
    if (gmailAccess.checked) {
      const query = gmailQuery.value.trim();
      const ids = [...new Set(gmailThreadIds.value.split(/[\s,]+/).filter(Boolean).map(parseGmailThreadId))];
      if (gmailMode.value === 'search' && !query) { permissionStatus.textContent = 'Enter the Gmail search this app may use.'; return; }
      if (gmailMode.value === 'threads' && (!ids.length || ids.length > 12 || ids.some(id => !mailboxIdPattern.test(id)))) {
        permissionStatus.textContent = 'Enter up to 12 valid Gmail thread IDs or links.';
        return;
      }
      gmail = gmailMode.value === 'search' ? {mode:'search',query} : {mode:'threads',thread_ids:ids};
    }
    const sources = Object.fromEntries(privateSourceControls.map(({name,control,label}) =>
      [name,label.hidden ? baseline.sources[name] : control.checked]));
    const settings = {app_data:{app_access:appAccess.checked,specialist_review:specialistReview.checked},
      gmail:{app_access:gmailAccess.checked,specialist_review:baseline.gmail.specialist_review,scope:gmail},sources};
    const stillSelected = () => selected === current && conversation === scope() && shown() && matches();
    savePermissions.disabled = true;
    permissionStatus.textContent = 'Saving data permissions…';
    try {
      const result = await api('save_command_grants',{canvas_id:selected.canvas.id,revision:selected.revision.id,
        expected_revision:baseline.revision,settings},conversation);
      if (!stillSelected()) return;
      const verified = normalizeCommandSettings(result.commands);
      if (!verified) throw new Error('Saved data permissions could not be verified.');
      await restoreAppState(selected,verified,conversation);
      if (!stillSelected()) return;
      setCommandSettings(verified);
      stopSourceRefreshes();
      startPreview();
      permissionStatus.textContent = 'Data permissions saved.';
      try { localStorage.setItem(commandChangesKey,JSON.stringify({owner,agent,canvasId:selected.canvas.id,key:crypto.randomUUID()})); }
      catch (_error) { /* Other tabs can refresh when focused. */ }
    } catch (error) {
      if (stillSelected()) permissionStatus.textContent = error.message;
    } finally {
      savePermissions.disabled = false;
    }
  }

  function parsePublication(data) {
    const record = data?.publication;
    const eligibility = data?.eligibility;
    if (!['unpublished','published'].includes(record?.state) || typeof record.version !== 'string' ||
      !record.version || record.version.length > 128 || typeof eligibility?.can_publish !== 'boolean' ||
      (record.slug !== null && (typeof record.slug !== 'string' || !publicSlugPattern.test(record.slug)))) return null;
    let url = null;
    if (record.state === 'published') {
      if (!record.slug || !Number.isSafeInteger(record.revision) || record.revision < 1 || typeof record.url !== 'string') return null;
      try {
        const parsed = new URL(record.url,location.origin);
        if (parsed.origin !== location.origin || parsed.username || parsed.password || parsed.search || parsed.hash ||
          parsed.pathname !== sourceBasePath + '/apps/' + record.slug) return null;
        url = parsed.href;
      } catch (_error) { return null; }
    }
    return {state:record.state,slug:record.slug,revision:record.revision,version:record.version,url,
      canPublish:eligibility.can_publish,reason:typeof eligibility.reason === 'string' ? eligibility.reason : ''};
  }

  function updatePublicationControls() {
    const published = publication?.state === 'published';
    const revision = current?.revision?.id;
    const active = revision === current?.canvas?.active_revision;
    const isPublicVersion = published && revision === publication.revision;
    const canPublish = publication?.canPublish && active && !isPublicVersion;
    publishingSummary.textContent = !publication ? 'Public publishing' : published ?
      `Public publishing · Version ${publication.revision} is live` : 'Public publishing · Private';
    publishingState.textContent = !publication ? '' : published ?
      (isPublicVersion ? `Version ${publication.revision} is public.` : `Version ${publication.revision} is public. You’re viewing version ${revision}.`) :
      'This Canvas is private.';
    publishingLink.hidden = !published;
    if (published) {
      publishingLink.href = publication.url;
      publishingLink.textContent = publication.url;
    } else {
      publishingLink.removeAttribute('href');
      publishingLink.textContent = '';
    }
    publishingNote.textContent = !publication || isPublicVersion ? '' : !active ? 'Make this version active before publishing it.' :
      !publication.canPublish ? publication.reason || 'This version is ineligible for public publishing.' : 'Anyone with the public link can open the published version.';
    publishingNote.hidden = !publishingNote.textContent;
    publishingFields.hidden = !canPublish && !published;
    publishingSlugLabel.hidden = !canPublish;
    publishingSlug.readOnly = Boolean(publication?.slug);
    publishingSlug.disabled = locked || Boolean(publicationRequest) || Boolean(publicationIntent);
    publish.hidden = !canPublish;
    publish.disabled = locked || Boolean(publicationRequest);
    publish.textContent = published ? 'Publish update…' : publication?.slug ? 'Republish version…' : 'Publish version…';
    unpublish.hidden = !published;
    unpublish.disabled = locked || Boolean(publicationRequest);
    const confirming = Boolean(publicationIntent);
    publicationActions.hidden = confirming;
    publishingConfirmation.hidden = !confirming;
    confirmPublishing.disabled = locked || Boolean(publicationRequest);
    updateProfileCanvasControls();
  }

  async function loadPublication(keepMessage = false,keepProfileMessage = false) {
    const selected = current;
    const conversation = scope();
    if (!selected?.revision || !editing() || !matches()) return;
    publicationRequest?.abort();
    profileCanvasRequest?.abort();
    profileCanvasRequest = null;
    const controller = new AbortController();
    publicationRequest = controller;
    const stillSelected = () => publicationRequest === controller && selected === current && conversation === scope() && editing() && matches();
    if (!keepMessage) publicationStatus.textContent = publication ? '' : 'Checking public publishing status…';
    retryPublication.hidden = true;
    updatePublicationControls();
    const timeout = setTimeout(() => controller.abort(),8000);
    let refreshProfile = false;
    try {
      const result = await api('publication_status',{canvas_id:selected.canvas.id,revision:selected.revision.id},conversation,controller.signal);
      if (!stillSelected()) return;
      const verified = parsePublication(result);
      if (!verified) throw new Error('Public publishing status could not be verified.');
      if (publicationIntent && publication?.version !== verified.version) cancelPublication(false);
      publication = verified;
      refreshProfile = true;
      if (verified.slug) publishingSlug.value = verified.slug;
      if (!keepMessage) publicationStatus.textContent = '';
    } catch (error) {
      if (!stillSelected()) return;
      publication = null;
      publicationIntent = null;
      publicationStatus.textContent = controller.signal.aborted ? 'Public publishing status timed out.' : error.message;
      retryPublication.hidden = false;
    } finally {
      clearTimeout(timeout);
      if (publicationRequest === controller) {
        publicationRequest = null;
        updatePublicationControls();
      }
    }
    if (refreshProfile && selected === current && conversation === scope() && editing() && matches()) void loadProfileCanvas(keepProfileMessage);
  }

  function parseProfileCanvas(data) {
    const record = data?.profile_canvas;
    const eligibility = data?.eligibility;
    if (typeof record?.listed !== 'boolean' || typeof record.version !== 'string' || !/^[a-f0-9]{64}$/.test(record.version) ||
      typeof eligibility?.can_list !== 'boolean' || (record.url !== null && typeof record.url !== 'string')) return null;
    let url = null;
    if (record.url !== null) {
      if (!record.listed || publication?.state !== 'published') return null;
      try {
        const parsed = new URL(record.url,location.origin);
        const path = parsed.pathname.slice(sourceBasePath.length);
        if (parsed.origin !== location.origin || parsed.username || parsed.password || parsed.search || parsed.hash ||
          !parsed.pathname.startsWith(sourceBasePath + '/') ||
          !/^\/[a-z0-9][a-z0-9._-]{1,58}[a-z0-9]\/canvas\/[a-z0-9-]{3,64}$/.test(path) ||
          !path.endsWith('/canvas/' + publication.slug)) return null;
        url = parsed.href;
      } catch (_error) { return null; }
    }
    return {listed:record.listed,version:record.version,url,canList:eligibility.can_list,
      reason:typeof eligibility.reason === 'string' ? eligibility.reason : ''};
  }

  function updateProfileCanvasControls() {
    const bound = Boolean(profileCanvas && publication && profileCanvasPublication === publication.version);
    const busy = locked || Boolean(publicationRequest) || Boolean(publicationIntent) || Boolean(profileCanvasRequest);
    const canAdd = bound && publication.state === 'published' && profileCanvas.canList;
    profileCanvasCheck.disabled = busy || !bound || (!profileCanvas.listed && !canAdd);
    saveProfileCanvas.disabled = busy || !bound || profileCanvasCheck.checked === profileCanvas.listed ||
      (profileCanvasCheck.checked && !canAdd);
    profileCanvasState.textContent = !bound ? '' : profileCanvas.listed ?
      (profileCanvas.url ? 'Shown in the Canvases tab on your Profile.' : 'Selected for your Profile. It appears only while the Profile and this Canvas are public.') :
      canAdd ? 'This Canvas is not shown on your Profile.' : profileCanvas.reason || 'Publish a version before adding this Canvas to your Profile.';
    profileCanvasLink.hidden = !bound || !profileCanvas.url;
    if (bound && profileCanvas.url) profileCanvasLink.href = profileCanvas.url;
    else profileCanvasLink.removeAttribute('href');
    retryProfileCanvas.disabled = busy || !publication;
  }

  async function profileCanvasApi(action,payload,conversationId,signal) {
    if (!matches()) throw new Error('Profile Canvas settings are available in your own Chat.');
    const endpoint = new URL('profile-canvases-v1.php',new URL(cfg.endpoint,location.href));
    const response = await fetch(endpoint,{
      method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},signal,
      body:JSON.stringify({action,csrf_token:cfg.csrf,agent_id:agent,conversation_id:conversationId,...payload})
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) throw new Error(typeof data?.error === 'string' ? data.error : 'Profile visibility could not be loaded.');
    return data;
  }

  async function loadProfileCanvas(keepMessage = false) {
    const selected = current;
    const conversation = scope();
    const publishedVersion = publication?.version;
    if (!selected?.revision || !publishedVersion || !editing() || !matches()) return;
    profileCanvasRequest?.abort();
    const controller = new AbortController();
    profileCanvasRequest = controller;
    const stillSelected = () => profileCanvasRequest === controller && selected === current && conversation === scope() &&
      publishedVersion === publication?.version && editing() && matches();
    if (!keepMessage) profileCanvasStatus.textContent = 'Checking Profile visibility…';
    retryProfileCanvas.hidden = true;
    updateProfileCanvasControls();
    const timeout = setTimeout(() => controller.abort(),8000);
    try {
      const result = await profileCanvasApi('status',{canvas_id:selected.canvas.id},conversation,controller.signal);
      if (!stillSelected()) return;
      const verified = parseProfileCanvas(result);
      if (!verified) throw new Error('Profile visibility could not be verified.');
      profileCanvas = verified;
      profileCanvasPublication = publishedVersion;
      profileCanvasCheck.checked = verified.listed;
      if (!keepMessage) profileCanvasStatus.textContent = '';
    } catch (error) {
      if (!stillSelected()) return;
      profileCanvas = null;
      profileCanvasPublication = null;
      profileCanvasCheck.checked = false;
      profileCanvasStatus.textContent = controller.signal.aborted ? 'Profile visibility check timed out. Reload to try again.' : error.message;
      retryProfileCanvas.hidden = false;
    } finally {
      clearTimeout(timeout);
      if (profileCanvasRequest === controller) {
        profileCanvasRequest = null;
        updateProfileCanvasControls();
      }
    }
  }

  async function commitProfileCanvas() {
    const selected = current;
    const baseline = profileCanvas;
    const publishedVersion = publication?.version;
    const conversation = scope();
    const version = epoch;
    const listed = profileCanvasCheck.checked;
    if (saveProfileCanvas.disabled || !baseline || !selected?.revision || !publishedVersion ||
      profileCanvasPublication !== publishedVersion || !editing() || !matches() || locked || profileCanvasRequest ||
      publicationRequest || publicationIntent || listed === baseline.listed ||
      (listed && (publication.state !== 'published' || !baseline.canList))) return;
    const controller = new AbortController();
    profileCanvasRequest = controller;
    const stillSelected = () => profileCanvasRequest === controller && selected === current && version === epoch &&
      conversation === scope() && publishedVersion === publication?.version && editing() && matches();
    locked = true;
    updateControls();
    profileCanvasStatus.textContent = listed ? 'Adding Canvas to your Profile…' : 'Removing Canvas from your Profile…';
    const timeout = setTimeout(() => controller.abort(),15000);
    let reload = false;
    try {
      const result = await profileCanvasApi('set',{canvas_id:selected.canvas.id,listed,
        expected_revision:baseline.version,expected_publication:publishedVersion},conversation,controller.signal);
      if (!stillSelected()) return;
      const verified = parseProfileCanvas(result);
      if (!verified || verified.listed !== listed) throw new Error('Profile visibility change could not be verified.');
      profileCanvas = verified;
      profileCanvasCheck.checked = verified.listed;
      profileCanvasStatus.textContent = listed ? 'Canvas added to your Profile.' : 'Canvas removed from your Profile.';
    } catch (error) {
      if (!stillSelected()) return;
      profileCanvasStatus.textContent = controller.signal.aborted ? 'The save response timed out. Review the current visibility before retrying.' : error.message;
      reload = true;
    } finally {
      clearTimeout(timeout);
      if (profileCanvasRequest === controller) profileCanvasRequest = null;
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
    if (reload && selected === current && conversation === scope() && editing() && matches()) await loadPublication(true,true);
  }

  function cancelPublication(focus) {
    const action = publicationIntent?.action;
    publicationIntent = null;
    updatePublicationControls();
    if (focus && action) (action === 'publish' ? publish : unpublish).focus();
  }

  function confirmPublication(action) {
    if (!publication || !current?.revision || !editing() || !matches() || locked || publicationRequest || publicationIntent) return;
    const revision = current.revision.id;
    if (action === 'publish' && (!publication.canPublish || revision !== current.canvas.active_revision || revision === publication.revision)) return;
    if (action === 'unpublish' && publication.state !== 'published') return;
    const slug = publication.slug ?? publishingSlug.value.trim();
    if (!publicSlugPattern.test(slug)) {
      publicationStatus.textContent = 'Use 3–64 lowercase letters and digits in the slug. Single hyphens can separate words.';
      publishingSlug.focus();
      return;
    }
    publicationStatus.textContent = '';
    publicationIntent = {action,slug,version:publication.version,selected:current};
    if (action === 'publish') {
      publishingConsent.textContent = `Anyone with the link can access version ${revision} at ${sourceBasePath}/apps/${slug}. Your saved records and connected accounts stay private. Review the app itself for any personal information before publishing.`;
      confirmPublishing.textContent = 'Publish publicly';
    } else {
      publishingConsent.textContent = `Take version ${publication.revision} offline at /apps/${slug}? You can use this link again when you republish.`;
      confirmPublishing.textContent = 'Confirm unpublish';
    }
    updatePublicationControls();
    confirmPublishing.focus();
  }

  async function commitPublication() {
    const intent = publicationIntent;
    const conversation = scope();
    const version = epoch;
    if (!intent || !publication || locked || publicationRequest || !editing() || !matches() || intent.selected !== current ||
      intent.version !== publication.version) return;
    const stillSelected = () => intent.selected === current && version === epoch && conversation === scope() && editing() && matches();
    const isPublish = intent.action === 'publish';
    locked = true;
    updateControls();
    publicationStatus.textContent = isPublish ? 'Publishing this version…' : 'Taking the public link offline…';
    try {
      const result = await api(intent.action,{canvas_id:current.canvas.id,revision:current.revision.id,expected_revision:intent.version,
        ...(isPublish ? {slug:intent.slug} : {})},conversation);
      if (!stillSelected()) return;
      const verified = parsePublication(result);
      if (!verified || verified.state !== (isPublish ? 'published' : 'unpublished') || verified.slug !== intent.slug ||
        (isPublish && verified.revision !== current.revision.id)) throw new Error('Could not verify the public publishing change.');
      publication = verified;
      publishingSlug.value = verified.slug;
      publicationStatus.textContent = isPublish ? 'Public link ready.' : 'Public link taken offline.';
      void loadProfileCanvas();
    } catch (error) {
      if (!stillSelected()) return;
      publicationStatus.textContent = error.message;
      await loadPublication(true);
    } finally {
      if (version === epoch) {
        publicationIntent = null;
        locked = false;
        updateControls();
      }
    }
  }

  function report(text) { notice.textContent = text; updateBuildProgress(); }
  function reportApp(text,retry = false) {
    appNotice.textContent = text;
    appRetry.hidden = !retry;
    appFeedback.hidden = !text;
  }
  function setMode(mode) {
    panel.dataset.mode = mode;
    thread.dataset.codingMode = mode;
    heading.textContent = mode === 'app' ? current?.canvas?.title || 'Canvas app' : 'Code / App Canvas';
    syncEditorViews();
  }
  function enterPanel(mode) {
    const runtime = chat();
    if (!matches() || !runtime?.isReady() || runtime.isRestoring()) return false;
    const wasShown = shown();
    const modeChanged = panel.dataset.mode !== mode;
    if (!wasShown) chatPosition = {conversation:scope(),top:thread.scrollTop,focus:document.activeElement};
    setMode(mode);
    if (!wasShown) {
      if (runtime.showCodingCanvasView) runtime.showCodingCanvasView();
      else {
        thread.dataset.view = 'coding';
        window.dispatchEvent(new Event('vp3:chat-canvas-view-changed'));
      }
      thread.scrollTo({top:0,behavior:'instant'});
    }
    if (!wasShown || modeChanged) heading.focus({preventScroll:true});
    return true;
  }
  function closePanel() {
    closePhoneDelivery();
    const position = chatPosition;
    chatPosition = null;
    chat()?.showChatView();
    if (!position || position.conversation !== scope() || !matches()) return;
    thread.scrollTo({top:position.top,behavior:'instant'});
    const target = position.focus?.isConnected && position.focus !== document.body ? position.focus : launch;
    target.focus({preventScroll:true});
  }
  function cancelOpen() {
    load++;
    openController?.abort();
    openController = null;
  }
  function showDelete(show) {
    deleteFolder.checked = false;
    deletion.hidden = !show;
    if (show) deleteConfirm.focus();
    else if (shown() && !workspace.hidden) remove.focus();
  }
  function closePreview() {
    if (!frame) return;
    clearTimeout(frame.timer);
    frame.element.remove();
    frame = null;
  }
  function validFrame(candidate) {
    return frame === candidate && candidate.epoch === epoch && candidate.scope === scope() &&
      Number(window.STONEFELLOW_CHAT?.userId) === owner && matches() && shown() && candidate.element.isConnected &&
      current?.canvas.id === candidate.canvasId && current?.revision?.id === candidate.revision;
  }

  function tested() {
    const revision = current?.revision;
    const snapshot = current?.state?.revision;
    if(revision?.workflow_checks?.length&&revision.workflow_result?.status!=='passed')return false;
    return Boolean(revision && snapshot && !sourceEditor?.dirty() && !frame?.localSource && !frame?.workflowMode && !previewFailed() && (revision.test_passed === true ||
      (browserTest?.canvas === current.canvas.id && browserTest?.revision === revision.id && browserTest?.snapshot === snapshot
        && browserTest.imageToken === libraryImageSettings()?.token)));
  }
  function previewFailed() {
    return failedPreview && failedPreview.canvas === current?.canvas.id && failedPreview.revision === current?.revision?.id;
  }

  function closeComparison(clear = true) {
    comparisonRead?.controller.abort();
    comparisonRead = null;
    if (clear) {
      comparisonStatus.textContent = '';
      comparisonResult.replaceChildren();
      compareReload.hidden = true;
    }
  }
  function setEditorTab(name) {
    if (!Object.hasOwn(viewPanels,name) || !editing() || !matches()) return;
    editorTab = name;
    syncEditorViews(true);
  }
  function syncEditorViews(read = false) {
    const inEditor = panel.dataset.mode === 'editor';
    for (const tab of viewButtons) {
      const selected = tab.dataset.canvasView === editorTab;
      tab.setAttribute('aria-selected',String(selected));
      tab.tabIndex = selected ? 0 : -1;
    }
    frameHost.hidden = inEditor && editorTab !== 'preview';
    source.hidden = !inEditor || editorTab !== 'source';
    changes.hidden = !inEditor || editorTab !== 'changes';
    if (!inEditor || editorTab !== 'changes') closeComparison();
    else if (read && current?.revision && editing() && matches()) void loadComparison();
  }
  function renderSource() {
    const language = ['html','css','js'].includes(sourceLanguage.value) ? sourceLanguage.value : 'html';
    const text = current?.revision?.artifact?.[language];
    if (sourceEditor) sourceEditor.show();
    else code.textContent = typeof text === 'string' ? text : '';
    sourceDescription.textContent = current?.revision ?
      'Saved '+({html:'HTML',css:'CSS',js:'JavaScript'}[language])+' source · Version '+current.revision.id+'. Describe edits in the instructions.' : '';
  }
  function sourceEdited() {
    if(sourceEditor?.dirty())workflowEditor?.cancel();
    if (sourceEditor?.dirty()) clearSourceContext();
    if (frame?.localSource || frameHost.dataset.sourcePreview==='unsaved') {
      closePreview();
      if (!sourceEditor?.dirty() && current?.revision && shown() && matches()) startPreview();
      else frameHost.replaceChildren(node('p','Source changed. Choose Preview edits to run the updated code.','vp3-coding-canvas-description'));
    }
    if (editorTab === 'changes' && current?.revision && editing()) void loadComparison();
    updateControls();
  }
  function clearSourceContext() {
    selectedSourceContext = null;
    sourceContextLabel.textContent = '';
    sourceContext.hidden = true;
  }
  function selectSourceContext(context,summary) {
    if (!current?.revision || !editing() || !matches() || sourceEditor?.dirty()) return;
    selectedSourceContext={canvas:current.canvas.id,revision:current.revision.id,context};
    sourceContextLabel.textContent=summary;sourceContext.hidden=false;editorControls.open=true;
    prompt.focus();report('Saved code selected. Describe the change, then choose Make requested change.');updateControls();
  }
  async function saveSourceDraft(pending) {
    const selected=current,version=epoch,selection=load,conversation=scope();
    if (!selected?.revision || !editing() || !matches() || locked || (activeJob(job)&&job.canvas_id===selected.canvas.id) || lastIntent?.pending
      || pending.doc.canvasId!==selected.canvas.id || pending.doc.revision!==selected.revision.id
      || pending.doc.scopeKey!==[owner,agent,conversation].join(':')) {
      const error=new Error('Finish the current request and open the source editor before saving.');error.status=409;throw error;
    }
    locked=true;updateControls();
    try {
      const data=await api('save_source',{canvas_id:pending.doc.canvasId,revision:pending.doc.revision,source_hash:pending.doc.sourceHash,
        expected_active_revision:pending.doc.expectedActive,expected_draft_revision:pending.doc.expectedDraft,request_id:pending.id,artifact:pending.artifact},conversation);
      if(version!==epoch||selection!==load||selected!==current||conversation!==scope()||!editing()||!matches())throw new DOMException('Editor context changed.','AbortError');
      if(data.canvas?.id!==selected.canvas.id||data.canvas.agent_id!==selected.canvas.agent_id||data.canvas.conversation_id!==selected.canvas.conversation_id
        || !Number.isSafeInteger(data.revision?.id)||data.revision.id<1||!/^[a-f0-9]{64}$/.test(data.revision.source_hash||'')
        || typeof data.source_saved!=='boolean'||!Array.isArray(data.revisions)||!data.state
        || !['html','css','js'].every(name=>data.revision.artifact?.[name]===pending.artifact[name])
        || JSON.stringify(data.revision.commands??[])!==JSON.stringify(selected.revision.commands??[]))throw new Error('The source save could not be verified. Save again to recover the same request.');
      return data;
    } finally {if(version===epoch){locked=false;updateControls();}}
  }
  function updatePreviewCheck() {
    previewCheck.hidden = !current?.revision;
    if (!current?.revision) { previewCheckText.textContent = ''; return; }
    const failed = previewFailed();
    previewCheck.dataset.state = sourceEditor?.dirty() || frame?.localSource ? 'pending' : failed ? 'failed' : frame?.testing ? 'checking' : frame?.ready && tested() ? 'passed' : 'pending';
    previewCheckText.textContent = frame?.workflowMode ? (frame.workflowMode==='record'?'Recording a workflow':'Checking saved workflows')+' · Disposable app data and sample sources.' : frame?.localSource ? 'Unsaved edits · '+(frame.ready?'Preview loaded with disposable app data. Save a draft before running the saved-version test.':'Loading an isolated preview with disposable app data…') :
      sourceEditor?.dirty() ? 'Unsaved source edits. Save a draft before testing or using this code.' : failed ? 'Version '+current.revision.id+' · '+failedPreview.diagnostic :
      frame?.testing ? 'Checking version '+current.revision.id+'…' :
      frame?.ready && tested() ? 'Version '+current.revision.id+' · '+(current.revision.workflow_checks?.length?'Saved workflow checks passed.':current.email_source ? 'Startup and sample email checks passed.' : 'Startup check passed.')+' Try the app’s controls before using it.' :
      frame?.ready ? 'Version '+current.revision.id+' loaded. Run Test preview, then try the app’s controls.' :
      'Loading the preview for version '+current.revision.id+'…';
  }
  function comparisonCurrent(capture) {
    return comparisonRead === capture && current === capture.selected && epoch === capture.epoch && load === capture.load
      && scope() === capture.conversation && editing() && editorTab === 'changes' && matches();
  }
  async function loadComparison() {
    closeComparison();
    const selected = current,target = Number(compareVersion.value);
    if (!selected?.revision || !editing() || editorTab !== 'changes' || !matches()) return;
    compareLabel.hidden=Boolean(sourceEditor?.dirty());
    if (sourceEditor?.dirty()) {renderComparison(selected.revision.artifact,sourceEditor.artifact(),selected.revision.id,'Unsaved edits');return;}
    if (!Number.isSafeInteger(target) || target < 1 || target === selected.revision.id) {
      comparisonStatus.textContent = 'This is the first saved version. A comparison will be available after another version is saved.';
      return;
    }
    if (!window.VP3CanvasDiffV1) { comparisonStatus.textContent = 'Reload the page to load version comparison.'; return; }
    const capture = {selected,target,epoch,load,conversation:scope(),controller:new AbortController(),
      active:selected.canvas.active_revision,draft:selected.canvas.draft_revision};
    comparisonRead = capture;
    comparisonStatus.textContent = 'Loading version '+target+' for comparison…';
    try {
      const data = await api('open',{canvas_id:selected.canvas.id,revision:target},capture.conversation,capture.controller.signal);
      if (!comparisonCurrent(capture)) return;
      if (data.canvas?.id !== selected.canvas.id || data.canvas.agent_id !== selected.canvas.agent_id
        || data.canvas.conversation_id !== selected.canvas.conversation_id || data.revision?.id !== target
        || data.canvas.active_revision !== capture.active || data.canvas.draft_revision !== capture.draft
        || !['html','css','js'].every(key => typeof data.revision.artifact?.[key] === 'string' && data.revision.artifact[key].length <= 60000)) {
        throw new Error('The saved versions changed. Reload the selected version before comparing again.');
      }
      renderComparison(data.revision.artifact,selected.revision.artifact,target,selected.revision.id);
    } catch (error) {
      if (!comparisonCurrent(capture) || error.name === 'AbortError') return;
      comparisonStatus.textContent = error.message;
      comparisonResult.replaceChildren();
      compareReload.hidden = false;
    } finally {
      if (comparisonRead === capture) comparisonRead = null;
    }
  }
  function renderComparison(before,after,baseline,selected) {
    const files = [['html','HTML'],['css','CSS'],['js','JavaScript']].map(([key,label]) =>
      ({key,label,...window.VP3CanvasDiffV1.compare(before[key],after[key])}));
    const added = files.reduce((n,file) => n+file.added,0),removed = files.reduce((n,file) => n+file.removed,0);
    const changed = files.filter(file => file.added || file.removed).length;
    comparisonStatus.textContent = changed ? 'Version '+baseline+' → '+selected+' · '+changed+' file'+(changed === 1 ? '' : 's')+' changed · +'+added+' / −'+removed+' lines' :
      'Versions '+baseline+' and '+selected+' have identical saved source.';
    const fragment = document.createDocumentFragment();
    let rendered = 0,omitted = 0;
    for (const file of files) {
      const section = node('section','','vp3-coding-canvas-diff-file');
      section.append(node('h4',file.label+(file.added || file.removed ? ' · +'+file.added+' / −'+file.removed : ' · No changes')));
      if (!file.added && !file.removed) { fragment.append(section); continue; }
      if (file.coarse) section.append(node('p','Large rewrite: removed and added blocks are grouped.','vp3-coding-canvas-description'));
      const rows = node('ol');
      rows.setAttribute('aria-label',file.label+' changes');
      let oldLine = 1,newLine = 1;
      function row(type,text) {
        const oldNumber = type === 'add' ? '' : oldLine++,newNumber = type === 'remove' ? '' : newLine++;
        if (rendered >= 800) { omitted++; return; }
        rendered++;
        const item = node('li','','vp3-coding-canvas-diff-row');
        item.dataset.change = type;
        const numbers = node('span',oldNumber+' '+newNumber,'vp3-coding-canvas-diff-numbers');
        numbers.setAttribute('aria-hidden','true');
        const marker = node('span',type === 'add' ? '+' : type === 'remove' ? '−' : ' ','vp3-coding-canvas-diff-marker');
        marker.setAttribute('aria-label',type === 'add' ? 'Added' : type === 'remove' ? 'Removed' : 'Unchanged');
        const textNode = node('code',text || ' ');
        item.append(numbers,marker,textNode);rows.append(item);
      }
      file.chunks.forEach((chunk,index) => {
        if (chunk.type !== 'equal' || chunk.lines.length <= 6) { for (const line of chunk.lines) row(chunk.type,line); return; }
        const first = index > 0 ? 3 : 0,last = index < file.chunks.length-1 ? 3 : 0;
        for (let i=0;i<first;i++) row('equal',chunk.lines[i]);
        const count = chunk.lines.length-first-last;
        if (rendered < 800) rows.append(node('li',count+' unchanged lines','vp3-coding-canvas-diff-gap'));
        oldLine+=count;newLine+=count;
        for (let i=chunk.lines.length-last;i<chunk.lines.length;i++) row('equal',chunk.lines[i]);
      });
      const inspect = button('View '+file.label+' source',() => {
        if (!matches() || !editing()) return;
        sourceLanguage.value = file.key;renderSource();setEditorTab('source');
      });
      section.append(rows,inspect);fragment.append(section);
    }
    if (omitted) fragment.append(node('p',omitted+' further lines are omitted from this comparison. Open Source to inspect the complete selected version.','vp3-coding-canvas-diff-limit'));
    comparisonResult.replaceChildren(fragment);
  }
  function updateControls() {
    const working = activeJob(job);
    const uncertain = lastIntent?.pending === true;
    const workflowBusy=workflowEditor?.busy()===true;
    build.disabled = locked || working || uncertain;
    emailStart.disabled = locked || working || uncertain;
    repair.disabled = locked || working || uncertain || !current?.revision;
    openInApp.disabled = locked || !current?.revision;
    sendPhone.disabled=locked||!current?.revision||current.canvas.hidden===true;
    stop.hidden = !working;
    stop.disabled = false;
    picker.disabled = locked;
    versions.disabled = locked;
    remove.disabled = locked || working;
    hideApp.disabled = locked || !current?.revision;
    hideApp.textContent = current?.canvas.hidden === true ? 'Show app in shortcuts' : 'Hide app from shortcuts';
    test.disabled = locked || working || Boolean(sourceEditor?.dirty()) || current?.revision?.status !== 'draft';
    apply.disabled = locked || working || Boolean(sourceEditor?.dirty()) || frame?.localSource || !current?.revision || !frame?.ready || !validFrame(frame) ||
      (!current.revision.can_rollback && (current.revision.status !== 'draft' || !tested())) || current.revision.id === current.canvas.active_revision;
    apply.textContent = current?.revision?.can_rollback ? 'Restore this version' : 'Use this version';
    viewWorking.hidden = !current?.canvas.active_revision || current.canvas.active_revision === current.revision?.id;
    viewWorking.disabled = locked;
    sourceEditor?.setBlocked(locked||workflowBusy||(working&&job.canvas_id===current?.canvas.id)||uncertain);
    sourceMakeChange.disabled=locked||working||uncertain||!selectedSourceContext||!prompt.value.trim();
    updateBuildProgress();
    updatePreviewCheck();
    updateLibraryImageControls();
    updatePublicationControls();
    testAndRepair.disabled=!workflowEditor||test.disabled||workflowBusy||!current?.workflow_testing?.ready||!current?.revision?.workflow_checks?.length;
    if(workflowBusy)for(const control of [build,emailStart,repair,test,apply,remove,hideApp,sourceMakeChange])control.disabled=true;
    workflowEditor?.update();
  }

  function workflowContext(){
    if(!current?.revision)return null;
    return {owner,agent,conversation:scope(),epoch,canvasId:current.canvas.id,revision:current.revision.id,
      sourceHash:current.revision.source_hash,stateRevision:current.state.revision,expectedActive:current.canvas.active_revision,
      expectedDraft:current.canvas.draft_revision,checks:current.revision.workflow_checks??[],report:current.revision.workflow_result??null,
      imageToken:libraryImageSettings()?.token??null,emailSource:current.email_source===true,
      ready:current.workflow_testing?.ready===true,editable:current.workflow_testing?.can_edit===true,
      canTest:current.revision.status==='draft',visible:editing()&&shown()&&matches()&&!document.hidden,
      blocked:locked||activeJob(job)||lastIntent?.pending===true||sourceEditor?.dirty()||sourceEditor?.pending()};
  }
  function workflowChanged(){
    if(!workflowEditor?.busy()&&frame?.workflowMode&&editing()&&shown()&&matches())startPreview();
    updateControls();
  }

  function updateBuildProgress() {
    requestSummary.textContent = activeJob(job) ? 'Instructions & checks · '+(jobLabels[job.state] || '') :
      previewFailed() ? 'Instructions & checks · Preview failed' : 'Instructions & checks';
    progress.hidden = !job && !current?.revision && !lastIntent?.pending && !notice.textContent;
    const phase = activeJob(job) || ['failed','stopped'].includes(job?.state) || lastIntent?.pending ? 0 : !current?.revision ? (job?.state === 'ready' ? 1 : 0) :
      current.revision.id === current.canvas.active_revision ? 3 : tested() ? 2 : 1;
    for (const [index,item] of stageItems.entries()) {
      item.dataset.state = index < phase ? 'complete' : index === phase ? 'current' : 'waiting';
      if (index === phase) item.setAttribute('aria-current','step');
      else item.removeAttribute('aria-current');
    }
    checkStatus.hidden = !job && !lastIntent?.pending;
    checkStatus.disabled = locked || checkingBuild;
    viewBuild.hidden = job?.state !== 'ready';
    viewBuild.disabled = locked || checkingBuild;
    retryBuild.hidden = job?.can_retry !== true || activeJob(job) || lastIntent?.pending === true;
    retryBuild.disabled = locked || checkingBuild;
    editRequest.hidden = !job;
    editRequest.disabled = locked || checkingBuild || activeJob(job);
    const attempt=job?.provider_attempt;
    attemptDetail.hidden=!job;
    attemptDetail.textContent=!job?'':attempt ? 'Provider status: '+attempt.status+' · '+
      (attempt.duration_ms>0?(attempt.duration_ms/1000).toFixed(1)+' s':'Elapsed time not yet confirmed')+' · '+
      (attempt.usage_known===true?Number(attempt.tokens).toLocaleString()+' tokens confirmed':'Token usage not confirmed; the provider may have charged') :
      ['failed','stopped'].includes(job.state)?'Provider status and token usage are unavailable for this run. Check AI usage or the provider account.':'Waiting for provider usage details.';
    liveSource?.update(editing()&&matches()&&!suspended&&!document.hidden&&job?.live_source_available===true ?
      {key:[owner,agent,scope(),epoch,job.run_id].join(':'),runId:job.run_id,state:job.state,
        blocked:locked||Boolean(sourceEditor?.dirty()||sourceEditor?.pending())||Boolean(prompt.value.trim())} : null);
    recoveryDetail.textContent = lastIntent?.pending ? 'The request has not been confirmed. Check its saved status before starting another build.' :
      pollingBlocked ? 'Automatic status checks stopped. Restore access, then check build status.' :
      pollingError ? 'Connection interrupted. Checking the existing run; a new build has not been started.' :
      job?.state === 'paused' ? 'This run is paused. Stop it before starting a replacement.' :
      job?.can_retry ? 'Retry starts a new provider attempt and can use more API tokens. Review received code first, or edit the saved request to change the brief.' :
      activeJob(job) && lastBuildCheck ? 'Saved run '+job.run_id+' · Status checked '+new Date(lastBuildCheck).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'}) :
      current?.revision ? (phase === 3 ? 'This version is active.' : phase === 2 ? 'Preview tested. Use this version when you are ready.' : 'Draft saved. Test the preview, then use this version.') : '';
  }

  function libraryImageSettings() {
    const settings = current?.library_image;
    return settings?.state === 'ready' && /^[a-f0-9]{64}$/.test(settings.token) ? settings : null;
  }
  function libraryImageEditable() {
    return editing() && matches() && ['edit','draft_copy'].includes(libraryImageSettings()?.mode);
  }
  function updateLibraryImageControls() {
    const settings = libraryImageSettings(),image = settings?.image;
    libraryImagePanel.hidden = !current?.revision || !current?.library_image;
    libraryFields.hidden = !libraryImageEditable();
    libraryInfo.textContent = !settings ? 'The image settings could not be verified.'
      : settings.mode === 'select_draft' ? 'Select the current private draft to attach an image. The existing public version stays online.'
      : settings.mode === 'draft_copy' ? 'Attaching a photo creates a private draft and keeps the public version online.'
      : image ? (image.available ? 'Attached photo · ' : 'The attached original is unavailable · ') + image.title
      : current?.canvas?.conversation_id !== scope() ? 'Open this Canvas in its original Chat to add an image.'
      : 'No photo attached to this version.';
    const choice = libraryRows.get(Number(librarySelect.value));
    const editable = libraryImageEditable();
    const disabled = !editable || locked || activeJob(job) || Boolean(libraryWrite || libraryRead);
    librarySearchButton.disabled = librarySelect.disabled = librarySearch.disabled = disabled;
    libraryMore.hidden = libraryCursor === null;
    libraryMore.disabled = disabled;
    libraryPreview.hidden = !editable || !choice;
    if (editable && choice) {
      if (libraryPreview.getAttribute('src') !== choice.url) libraryPreview.src = choice.url;
      libraryPreview.alt = choice.title;
    } else libraryPreview.removeAttribute('src');
    attachedAction.disabled = disabled || !choice || choice.id === image?.id && image.available === true;
    attachedAction.textContent = settings?.mode === 'draft_copy' ? 'Create private draft with photo' : image ? 'Replace photo' : 'Attach photo';
    detachAction.hidden = !image || settings?.mode !== 'edit';
    detachAction.disabled = disabled;
  }

  function resetLibraryImagePicker() {
    libraryRead?.abort();
    libraryRead = null;
    libraryWrite?.abort();
    libraryWrite = null;
    libraryRows.clear();
    libraryCursor = null;
    libraryQuery = '';
    librarySearch.value = '';
    librarySelect.replaceChildren();
    libraryImageStatus.textContent = '';
    libraryImagePanel.open = false;
  }

  async function browseLibraryImages(more = false) {
    if (!libraryImageEditable() || locked || activeJob(job) || libraryWrite || libraryRead || more && libraryCursor === null) return;
    const bound = current, conversation = scope(), view = epoch;
    const controller = libraryRead = new AbortController();
    const selected = () => libraryRead === controller && current === bound && conversation === scope()
      && view === epoch && libraryImagePanel.open && libraryImageEditable();
    const url = new URL('media-library-v86.php',new URL(cfg.endpoint,location.href));
    url.searchParams.set('action','browse');
    url.searchParams.set('type','photo');
    if (!more) {
      libraryQuery = librarySearch.value.trim();
      libraryRows.clear();
      libraryCursor = null;
      librarySelect.replaceChildren();
    }
    if (libraryQuery) url.searchParams.set('q',libraryQuery);
    if (more) url.searchParams.set('before',String(libraryCursor));
    libraryImageStatus.textContent = 'Loading your saved photos…';
    updateLibraryImageControls();
    try {
      if (url.origin !== location.origin) throw new Error('Private Media Library is unavailable.');
      const response = await fetch(url,{credentials:'same-origin',cache:'no-store',signal:controller.signal,
        headers:{'X-VP3-Media-Owner':String(owner)}});
      const data = await response.json();
      if (!selected()) return;
      if (!response.ok || data?.ok !== true || !Array.isArray(data.assets)) throw new Error('Private Media Library is unavailable.');
      if (!more) librarySelect.append(option('Choose a photo',''));
      for (const asset of data.assets.slice(0,24)) {
        if (!Number.isSafeInteger(asset?.id) || asset.id < 1 || libraryRows.has(asset.id) || asset.available !== true
            || asset.media_type !== 'photo' || !['image/png','image/jpeg','image/webp'].includes(asset.mime_type)
            || asset.file_size > 4194304 || typeof asset.title !== 'string' || typeof asset.url !== 'string') continue;
        let link;
        try { link = new URL(asset.url,location.href); } catch (_) { continue; }
        if (link.origin !== location.origin || link.username || link.password || link.hash
            || link.pathname !== sourceBasePath + '/media-library-file-v86.php' || link.search !== '?id=' + asset.id) continue;
        const title = asset.title.trim().slice(0,190) || 'Media Library photo';
        libraryRows.set(asset.id,{id:asset.id,title,url:link.href});
        librarySelect.append(option(title,asset.id));
      }
      libraryCursor = Number.isSafeInteger(data.next_before) && data.next_before > 0 ? data.next_before : null;
      libraryImageStatus.textContent = libraryRows.size ? '' : 'No compatible photos found.';
    } catch (error) {
      if (selected()) libraryImageStatus.textContent = error.message || 'Private Media Library is unavailable.';
    } finally {
      if (libraryRead === controller) libraryRead = null;
      updateLibraryImageControls();
    }
  }

  async function saveLibraryImage(id) {
    const settings = libraryImageSettings();
    if (!settings || !libraryImageEditable() || locked || activeJob(job) || libraryRead || libraryWrite
        || (id === null ? !settings.image : !libraryRows.has(id))) return;
    const bound = current, conversation = scope(), view = epoch;
    const controller = libraryWrite = new AbortController();
    const selected = () => libraryWrite === controller && current === bound && conversation === scope()
      && view === epoch && libraryImageEditable();
    libraryImageStatus.textContent = id === null ? 'Removing the image…' : 'Attaching the saved photo…';
    updateLibraryImageControls();
    try {
      const data = await api('save_library_image',{canvas_id:bound.canvas.id,revision:bound.revision.id,
        expected_image:settings.token,asset_id:id},conversation,controller.signal);
      if (!selected()) return;
      const cloned = settings.mode === 'draft_copy';
      if (data.canvas?.id !== bound.canvas.id || data.library_image?.state !== 'ready'
          || !Array.isArray(data.revisions) || !data.state
          || (data.library_image.image?.id ?? null) !== id
          || (cloned ? data.revision?.status !== 'draft' || data.revision?.id !== data.canvas.draft_revision
            || data.revision.id <= bound.revision.id : data.revision?.id !== bound.revision.id)) {
        throw new Error('The saved Canvas image could not be verified.');
      }
      libraryWrite = null;
      browserTest = null;
      renderCanvas(data);
      libraryImagePanel.open = true;
      report(cloned ? 'Private draft created with the photo. The public version stays online.'
        : id === null ? 'Photo removed from this version.' : 'Photo attached to this version.');
    } catch (error) {
      if (selected()) libraryImageStatus.textContent = error.message || 'The Canvas image could not be saved.';
    } finally {
      if (libraryWrite === controller) libraryWrite = null;
      updateLibraryImageControls();
    }
  }

  async function api(action,payload = {},conversationId = scope(),signal) {
    if (!matches()) throw new Error('Private Canvas is available in your own Chat.');
    const controller = new AbortController();
    let cancellation;
    const abort = () => { cancellation = signal.reason || new DOMException('Canvas request cancelled.','AbortError'); controller.abort(cancellation); };
    if (signal?.aborted) abort();
    else signal?.addEventListener('abort',abort,{once:true});
    const timeout = Object.assign(new Error('The Canvas connection timed out. Check the saved build status before retrying.'),{code:'canvas_timeout'});
    const timer = setTimeout(() => { cancellation = timeout; controller.abort(timeout); },action === 'tick' ? 110000 : 15000);
    try {
      const cancelled = new Promise((_,reject) => {
        if (controller.signal.aborted) reject(cancellation);
        else controller.signal.addEventListener('abort',() => reject(cancellation),{once:true});
      });
      const operation = (async () => {
        const response = await fetch(cfg.endpoint,{
          method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},signal:controller.signal,
          body:JSON.stringify({action,csrf_token:cfg.csrf,agent_id:agent,conversation_id:conversationId,...payload})
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) throw Object.assign(new Error(typeof data?.error === 'string' ? data.error : 'Canvas request did not complete.'),{status:response.status});
        return data;
      })();
      return await Promise.race([operation,cancelled]);
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort',abort);
    }
  }

  function closeArtworkFrame() {
    if (!artworkFrame) return;
    clearTimeout(artworkFrame.timer);
    artworkFrame.element.remove();
    artworkFrame = null;
  }

  function requestArtwork() {
    if (!cfg.artworkEndpoint || sourceEditor?.dirty() || frame?.localSource || frame?.workflowMode || document.hidden || suspended || !shown() || !matches() || !current?.canvas || !current?.revision) return;
    const {canvas,revision} = current;
    const binding = String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '');
    const context = {canvasId:canvas.id,revision:revision.id,conversationId:scope(),agentId:agent,ownerId:owner,binding};
    if (!context.conversationId) return;
    if (!canvas.icon_url) window.dispatchEvent(new CustomEvent('vp3:canvas-preview-tested',{detail:context}));
    const capture = window.VP3CanvasThumbnailV1?.capture;
    const attempt = canvas.id + ':' + revision.id;
    if (typeof capture !== 'function' || canvas.thumbnail_revision === revision.id || artworkAttempts.has(attempt)
        || canvas.active_revision && canvas.active_revision !== revision.id) return;
    if (artworkAttempts.size >= 64) artworkAttempts.delete(artworkAttempts.values().next().value);
    artworkAttempts.add(attempt);
    closeArtworkFrame();
    const element = node('iframe','','vp3-coding-canvas-artwork-frame');
    const nonce = token();
    element.setAttribute('sandbox','allow-scripts');
    element.setAttribute('aria-hidden','true');
    element.tabIndex = -1;
    element.referrerPolicy = 'no-referrer';
    element.title = 'Sample app thumbnail';
    // A second isolated preview supplies only empty app storage or the existing fixed Gmail fixture.
    element.srcdoc = previewDocument({channel,nonce,parentOrigin:location.origin,artifact:revision.artifact,
      state:{},workerKey:token(),maxText,stateLimit,email:current.email_source ? emailFixture() : null,emailCheck:null,
      gmail:declaresGmail() ? gmailFixture() : null,canSaveState:false,
      sources:previewSources(declaredCommands(),false).sources},capture);
    const timer = setTimeout(closeArtworkFrame,6500);
    artworkFrame = {...context,nonce,element,timer};
    panel.append(element);
  }

  async function acceptArtwork(event) {
    const candidate = artworkFrame;
    if (!candidate || event.origin !== 'null' || event.source !== candidate.element.contentWindow ||
      event.data?.channel !== channel || event.data?.nonce !== candidate.nonce) return;
    if (event.data.kind === 'error') { closeArtworkFrame(); return; }
    if (event.data.kind !== 'thumbnail') return;
    closeArtworkFrame();
    const currentContext = () => matches() && candidate.binding === String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '')
      && candidate.conversationId === scope();
    const image = event.data.imageBase64;
    if (!currentContext() || typeof image !== 'string' || image.length > 90000 || !/^[a-zA-Z0-9+/]+=*$/.test(image)) return;
    try {
      const response = await fetch(cfg.artworkEndpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'save_artwork',csrf_token:cfg.csrf,agent_id:agent,conversation_id:candidate.conversationId,
          canvas_id:candidate.canvasId,revision:candidate.revision,kind:'thumbnail',image_base64:image})});
      const saved = await response.json().catch(() => null);
      if (!response.ok || !currentContext() || saved?.canvas?.id !== candidate.canvasId
        || saved.canvas.thumbnail_revision !== candidate.revision || typeof saved.canvas.thumbnail_url !== 'string') return;
      if (current?.canvas?.id === candidate.canvasId) current.canvas.thumbnail_revision = candidate.revision;
      const {canvasId,revision,conversationId,binding} = candidate;
      window.dispatchEvent(new CustomEvent('vp3:canvas-artwork-updated',{detail:{
        canvasId,revision,conversationId,binding,agentId:agent,ownerId:owner,artwork:saved.canvas
      }}));
    } catch (_) { /* The saved draft and test result are independent of artwork. */ }
  }

  function startPreview(testing = false,localArtifact = null,workflowOptions = null) {
    closePreview();
    if (!shown() || !current?.revision || !current?.state || !matches()) return;
    const connected = current.email_source === true;
    const commands = declaredCommands();
    if ((connected || declaresGmail()) && document.hidden) return;
    if (!editing()) reportApp('Starting app…');
    const localSource=localArtifact!==null;
    if(localSource)frameHost.dataset.sourcePreview='unsaved';else delete frameHost.dataset.sourcePreview;
    const snapshot = connected && !testing && !localSource && !workflowOptions ? ensureSourceRefresh('email') : null;
    const email = snapshot ? {status:snapshot.generation > 0 ? 'ready' : 'unavailable',rows:snapshot.rows.map(({source_ref,...row}) => row)} :
      (connected ? emailFixture() : null);
    const element = node('iframe');
    const nonce = token();
    const artifact = localArtifact ?? current.revision.artifact;
    const isActive = !testing && !localSource && !workflowOptions && current.revision.id === current.canvas.active_revision;
    const gmailConnected = isActive && declaresGmail() && commandSettings?.gmail.app_access === true;
    const gmail = declaresGmail() ? (isActive ? gmailConnected ? ensureSourceRefresh('gmail') : gmailUnavailable : gmailFixture()) : null;
    const {sources,links} = previewSources(commands,isActive);
    const appDataAccess = commandSettings?.app_data.app_access ?? (!current.hasVerifiedGrants && !commands?.length);
    const state = workflowOptions?workflowOptions.state:appDataAccess ? current.state.value : {};
    const canSaveState = appDataAccess && !connected && !gmailConnected;
    const candidate = {
      nonce,element,epoch,scope:scope(),canvasId:current.canvas.id,revision:current.revision.id,
      stateRevision:current.state.revision,imageToken:libraryImageSettings()?.token,isActive,testing,state,snapshot,links,canSaveState,
      ready:false,saving:false,timer:0,localSource,workflowMode:workflowOptions?.mode??null,channel
    };
    const payload = {channel,nonce,parentOrigin:location.origin,artifact,state,workerKey:token(),maxText,stateLimit,email,
      emailCheck:connected && (testing||workflowOptions?.mode==='check') ? emailFixtureSubjects : null,gmail,canSaveState,sources,
      workflowMode:workflowOptions?.mode??null,
      libraryImage:current.library_image?.image,
      openableSources:workflowOptions?{}:Object.fromEntries(Object.entries(links).map(([key,records]) => [key,records.map(row => row.id)]))};
    element.title = current.canvas.title + (isActive ? ' app' : ' preview');
    element.setAttribute('sandbox','allow-scripts');
    element.referrerPolicy = 'no-referrer';
    element.srcdoc = previewDocument(payload);
    frame = candidate;
    frameHost.replaceChildren(element);
    candidate.timer = setTimeout(() => failPreview(candidate,'timeout'),10000);
    updatePreviewCheck();
  }

  function failPreview(candidate,reason) {
    if (!validFrame(candidate)) return;
    if(candidate.workflowMode){workflowEditor?.fault(candidate,reason);closePreview();updateControls();return;}
    closePreview();
    const diagnostic = Object.hasOwn(previewErrors,reason) ? previewErrors[reason] : previewErrors.render;
    if (candidate.localSource) {
      frameHost.replaceChildren(node('p',diagnostic+' Return to Source to edit this preview.','vp3-coding-canvas-preview-error'));
      sourceEditor?.reportPreviewFailure(diagnostic);report('Unsaved preview failed. '+diagnostic);updateControls();return;
    }
    failedPreview = {canvas:candidate.canvasId,revision:candidate.revision,diagnostic};
    frameHost.replaceChildren(node('p',diagnostic+' Use Repair to prepare a change.','vp3-coding-canvas-preview-error'));
    const detail = reason === 'email_workflow' ?
      'The email deadline test failed. Show current deadlines in date order and leave out outdated deadlines.' :
      'Preview failed. ' + diagnostic + ' Open the source or choose Repair.';
    report(detail);
    if (!editing()) reportApp(diagnostic + ' Retry opening it or choose Edit app to repair this version.',true);
    if (candidate.testing) locked = false;
    updateControls();
  }

  async function saveFromPreview(candidate,data) {
    const respond = (value,error) => {
      if (validFrame(candidate)) candidate.element.contentWindow.postMessage({channel,nonce:candidate.nonce,kind:'saved',id:data.id,value,error},'*');
    };
    if (!candidate.ready || !candidate.canSaveState || candidate.saving || typeof data.json !== 'string' ||
      data.json.length > stateLimit || !Number.isSafeInteger(data.id)) {
      respond(null,'Canvas data cannot be saved right now.');
      return;
    }
    let value;
    try {
      value = JSON.parse(data.json);
      if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid canvas data.');
    } catch (_error) {
      respond(null,'Canvas data must be a JSON object.');
      return;
    }
    if (!candidate.isActive) {
      candidate.state = value;
      respond(value);
      return;
    }
    candidate.saving = true;
    try {
      const saved = await api('save_state',{canvas_id:candidate.canvasId,revision:candidate.revision,state_revision:candidate.stateRevision,state:value},candidate.scope);
      if (!validFrame(candidate)) return;
      candidate.stateRevision = saved.state.revision;
      candidate.state = saved.state.value;
      current.state = saved.state;
      respond(saved.state.value);
      updateControls();
    } catch (error) {
      if (!validFrame(candidate)) return;
      respond(null,error.message);
      report('Data was not saved. ' + error.message);
    } finally {
      candidate.saving = false;
    }
  }

  window.addEventListener('message',event => {
    if (artworkFrame && event.source === artworkFrame.element.contentWindow) {
      void acceptArtwork(event);
      return;
    }
    const candidate = frame;
    if (!candidate || !validFrame(candidate) || event.origin !== 'null' || event.source !== candidate.element.contentWindow ||
      event.data?.channel !== channel || event.data?.nonce !== candidate.nonce) return;
    if (event.data.kind === 'error') failPreview(candidate,event.data.reason);
    if (event.data.kind === 'email_test_failed' && (candidate.testing||candidate.workflowMode==='check')) failPreview(candidate,'email_workflow');
    if (event.data.kind === 'save') void saveFromPreview(candidate,event.data);
    if (event.data.kind === 'open_email' && candidate.snapshot && !candidate.testing && !candidate.workflowMode) {
      const row = candidate.snapshot.rows.find(item => item.id === event.data.id);
      const binding = window.STONEFELLOW_CHAT?.workingAttentionBinding;
      if (row && !window.VP3GmailInboxInitialV1?.openTriageItem({id:row.source_ref,generation:candidate.snapshot.generation,
        binding,agent,conversation:candidate.scope})) report('The source email is unavailable in this Chat.');
    }
    if (event.data.kind === 'open_source' && candidate.ready && !candidate.testing && !candidate.workflowMode && publicSourceKeys.includes(event.data.command)) {
      const link = candidate.links[event.data.command]?.find(record => record.id === event.data.id);
      if (link) location.assign(link.url);
    }
    if (event.data.kind === 'ready' && !candidate.ready) {
      candidate.ready = true;
      clearTimeout(candidate.timer);
      if (!editing()) reportApp('');
      if(candidate.workflowMode){workflowEditor?.message(candidate,event.data);updateControls();}
      else if (candidate.testing) void recordTest(candidate,event.data.emailWorkflowChecked === true);
      else {
        if (current?.revision?.test_passed === true) requestArtwork();
        updateControls();
      }
    }
    if(candidate.workflowMode&&event.data.kind!=='ready')workflowEditor?.message(candidate,event.data);
  });

  function option(label,value) {
    const el = node('option',label);
    el.value = value;
    return el;
  }

  function renderCanvas(data) {
    const sameCanvas = Boolean(data?.canvas?.id && data.canvas.id === current?.canvas?.id);
    const comparisonTarget = sameCanvas ? Number(compareVersion.value) : null;
    closeComparison();
    if (!sameCanvas) {
      editorTab = 'preview'; sourceLanguage.value = 'html';
      // Put the saved app within reach on a phone. Keep an unsent instruction
      // visible, and keep the build form open when there is no selected app.
      editorControls.open = !data?.revision || window.innerWidth > 1100 || Boolean(prompt.value.trim());
    }
    closePhoneDelivery();phoneNotice.textContent='';
    const keepPublishingOpen = publishing.open && Boolean(data) && current?.canvas.id === data?.canvas?.id;
    publicationRequest?.abort();
    publicationRequest = null;
    publication = null;
    publicationIntent = null;
    publicationStatus.textContent = '';
    profileCanvasRequest?.abort();
    profileCanvasRequest = null;
    profileCanvas = null;
    profileCanvasPublication = null;
    profileCanvasCheck.checked = false;
    profileCanvasStatus.textContent = '';
    retryProfileCanvas.hidden = true;
    publishingSlug.value = '';
    retryPublication.hidden = true;
    commandSettingsRequest?.abort();
    stopSourceRefreshes();
    closePreview();
    resetLibraryImagePicker();
    current = data;
    clearSourceContext();
    sourceEditor?.bind(data?.revision?{scopeKey:[owner,agent,scope()].join(':'),canvasId:data.canvas.id,revision:data.revision.id,
      sourceHash:data.revision.source_hash||'',expectedDraft:data.canvas.draft_revision,expectedActive:data.canvas.active_revision,
      artifact:data.revision.artifact,ready:data.source_editor?.ready===true,
      editable:data.source_editor?.ready===true&&data.source_editor?.can_edit===true&&/^[a-f0-9]{64}$/.test(data.revision.source_hash||'')}:null);
    workflowEditor?.bind();
    publishing.open = keepPublishingOpen;
    permissions.open = false;
    permissionStatus.textContent = '';
    setCommandSettings(normalizeCommandSettings(data?.command_grants));
    if (data && commandSettings?.app_data.app_access === false) clearPrivateAppState(data);
    if (shown() && !editing()) setMode('app');
    workspace.hidden = !data?.revision;
    editorEmpty.hidden = Boolean(data?.revision);
    deletion.hidden = true;
    picker.value = data ? String(data.canvas.id) : '';
    if (!data?.revision) {
      workspaceTitle.textContent = '';
      versionStatus.textContent = '';
      previewDescription.textContent = '';
      renderSource();
      versions.replaceChildren();
      compareVersion.replaceChildren();
      frameHost.replaceChildren();
      delete frameHost.dataset.sourcePreview;
      syncEditorViews();
      updateControls();
      return;
    }
    workspaceTitle.replaceChildren(canvasArt(data.canvas),document.createTextNode(data.canvas.title));
    const revision = data.revision;
    const isActive = revision.id === data.canvas.active_revision;
    versionStatus.textContent = (data.canvas.active_revision ? 'Working version '+data.canvas.active_revision : 'No working version yet')+
      ' · Reviewing version '+revision.id+' ('+revision.status+')';
    previewDescription.textContent = data.email_source ? 'Uses current Gmail deadline data through the Email Agent.' : isActive ?
      'Active app. Changes are saved in your Knowledge Base folder.' :
      'Preview. App changes here are temporary. The saved app data is kept when you switch versions.';
    versions.replaceChildren(...data.revisions.map(item => option(
      'Version ' + item.id + ' · ' + item.status,item.id
    )));
    versions.value = String(revision.id);
    const comparable = data.revisions.filter(item => item.id !== revision.id);
    compareVersion.replaceChildren(...comparable.map(item => option('Version '+item.id+' · '+item.status,item.id)));
    const preferred = comparable.find(item => item.id === comparisonTarget) || comparable.find(item => item.id === data.canvas.active_revision)
      || comparable.find(item => item.id < revision.id) || comparable[0];
    if (preferred) compareVersion.value = String(preferred.id);
    compareVersion.disabled = !comparable.length;
    renderSource();
    startPreview();
    syncEditorViews(true);
    if (declaredCommands()?.length && !commandSettings) void loadCommandSettings();
    if (keepPublishingOpen) void loadPublication();
    updateControls();
  }

  function canvasArt(item) {
    const fallback = node('span',Array.from(item.title.trim())[0]?.toUpperCase() || 'C','vp3-coding-canvas-app-icon');
    fallback.setAttribute('aria-hidden','true');
    let artwork = fallback;
    if (typeof item.icon_url === 'string' && item.icon_url) {
      try {
        const url = new URL(item.icon_url,location.href);
        if (url.origin === location.origin && !url.username && !url.password) {
          artwork = node('img','','vp3-coding-canvas-app-icon');
          artwork.width = 15;
          artwork.height = 15;
          artwork.alt = '';
          artwork.loading = 'lazy';
          artwork.addEventListener('error',() => artwork.replaceWith(fallback),{once:true});
          artwork.src = url.href;
        }
      } catch (_error) { /* A missing icon leaves the app accessible by name. */ }
    }
    return artwork;
  }

  function canvasShortcut(item, showName) {
    const revision = item.active_revision || item.draft_revision;
    const shortcut = button('',() => void window.VP3CodingCanvasV1?.open(item.id,revision));
    shortcut.dataset.canvasId = item.id;
    shortcut.setAttribute('aria-label',item.title);
    shortcut.setAttribute('aria-controls',panel.id);
    shortcut.title = item.title;
    shortcut.append(canvasArt(item));
    if (showName) shortcut.append(node('span',item.title,'vp3-coding-canvas-app-name'));
    return shortcut;
  }

  function renderAppIcons(canvases) {
    const iconList = JSON.stringify(canvases.map(item => [item.id,item.title,item.icon_url,item.active_revision || item.draft_revision]));
    if (iconList === renderedAppIcons) return;
    renderedAppIcons = iconList;
    for (const group of [appIcons,editorAppIcons]) {
      const focused = group.contains(document.activeElement) && document.activeElement.dataset.canvasId;
      const scroll = group.scrollLeft;
      const icons = canvases.map(item => canvasShortcut(item,group === editorAppIcons));
      group.replaceChildren(...icons);
      group.hidden = icons.length === 0;
      group.scrollLeft = scroll;
      if (focused) icons.find(icon => icon.dataset.canvasId === focused)?.focus({preventScroll:true});
    }
  }

  async function refreshList(version = epoch,footerOnly = !editing()) {
    if (!matches() || !chat()?.isReady() || chat()?.isRestoring() || suspended || document.hidden) return;
    const request = ++listRequest;
    const editorRequest = footerOnly ? 0 : ++editorListRequest;
    const conversation = scope();
    const stillCurrent = () => version === epoch && conversation === scope() && matches() && !suspended && !document.hidden;
    let data;
    try {
      data = await api('list',{},footerOnly ? 0 : conversation);
    } catch (error) {
      if (stillCurrent() && request > commandCatalogRequest && !commandCatalog) renderCommandCatalog(null);
      throw error;
    }
    if (!stillCurrent()) return;
    if (request > commandCatalogRequest) {
      commandCatalogRequest = request;
      renderCommandCatalog(data.command_catalog);
    }
    if (!Array.isArray(data.canvases)) throw new Error('Saved canvases could not be loaded.');
    const canvases = data.canvases.filter(item => typeof item?.id === 'string' && canvasIdPattern.test(item.id)
      && item.agent_id === agent && typeof item.title === 'string' && item.title.trim());
    if (request === listRequest) renderAppIcons(canvases.filter(item => {
      const revision = item.active_revision || item.draft_revision;
      return item.hidden !== true && Number.isSafeInteger(revision) && revision > 0;
    }));
    if (!editing() || footerOnly || editorRequest !== editorListRequest) return;
    emailStart.hidden = data.email_start_available !== true;
    picker.replaceChildren(option('Choose a saved canvas',''),...canvases.map(item => option(item.title + (item.hidden === true ? ' (hidden)' : ''),item.id)));
    if (current) picker.value = String(current.canvas.id);
  }

  function scheduleFooterRefresh() {
    clearTimeout(footerTimer);
    footerTimer = setTimeout(() => void refreshList(epoch,true).catch(() => {}),150);
  }

  async function openSaved(id,revision,announce = true) {
    const version = epoch;
    const conversation = scope();
    cancelOpen();
    const selection = load;
    if (!id) {
      renderCanvas(null);
      return false;
    }
    const controller = new AbortController();
    openController = controller;
    const stillSelected = () => version === epoch && selection === load && conversation === scope() && shown() && matches();
    const fields = {canvas_id:id};
    if (revision) fields.revision = revision;
    try {
      const data = await api('open',fields,conversation,controller.signal);
      if (!stillSelected()) return false;
      if (data.canvas?.id !== id || typeof data.canvas.title !== 'string' || !data.canvas.title.trim() ||
        !Number.isSafeInteger(data.revision?.id) || revision !== undefined && data.revision.id !== revision ||
        !Array.isArray(data.revisions) || !data.state || !['html','css','js'].every(key => typeof data.revision.artifact?.[key] === 'string')) {
        throw new Error('This saved Canvas version could not be verified.');
      }
      renderCanvas(data);
      if (announce) report('');
      return true;
    } catch (error) {
      if (stillSelected()) {
        report(error.message);
        if (!editing()) reportApp(error.message,true);
      }
      return false;
    } finally {
      if (openController === controller) openController = null;
    }
  }

  function openPanel(restoreSelection = true) {
    const wasShown = shown();
    const wasEditing = editing();
    if (!enterPanel('editor')) return;
    const version = epoch;
    if (!wasShown && restoreSelection) {
      if (current?.revision) void openSaved(current.canvas.id,current.revision.id,false);
      else if (job?.state === 'ready') void openSaved(job.canvas_id,job.revision,false);
    }
    if (wasShown && !wasEditing && publishing.open && !publicationRequest) void loadPublication();
    if (editorTab === 'changes' && current?.revision) void loadComparison();
    void refreshList(version).catch(error => {
      if (version === epoch && editing()) report(error.message);
    });
  }

  async function openApp(id,revision) {
    if (typeof id !== 'string' || !canvasIdPattern.test(id) ||
      (revision !== undefined && (!Number.isSafeInteger(revision) || revision < 1))) return false;
    if (!enterPanel('app')) return false;
    appTarget = {id,revision};
    if (current?.canvas.id === id && current.revision?.id === revision && frame?.ready && !frame.testing && !frame.localSource && !frame.workflowMode && !openController) {
      reportApp('');
      return true;
    }
    renderCanvas(null);
    reportApp('Opening app…');
    return openSaved(id,revision);
  }

  function publishSpecialist(next, status, model) {
    const rail = window.VP3ChatSpecialists;
    if (!rail) return;
    if (!next) {
      for (const key of specialistJobs) rail.remove(key);
      specialistJobs.clear();
      return;
    }
    const key = specialistKey(next);
    if (!key || !status) return;
    specialistJobs.add(key);
    if (specialistJobs.size > 8) {
      const oldest = specialistJobs.values().next().value;
      specialistJobs.delete(oldest);
      rail.remove(oldest);
    }
    const ready = next.state === 'ready';
    const working = activeJob(next);
    rail.update(key, {
      type:'coding',state:next.state,label:'Coding Agent',title:model,
      status:next.state === 'failed' && next.reason ? next.reason : status,
      detail:'Building a private app draft for this Chat.',
      expiresAt:working ? Date.now()+12000 : 0,
      desktop:next.state === 'running',inline:true,
      viewLabel:ready ? 'Open draft' : 'Open Code / App Canvas',
      onView:ready || working ? () => {
        if (!matches() || next.conversation_id !== scope()) return;
        if (ready) void window.VP3CodingCanvasV1.open(next.canvas_id,next.revision);
        else if (job?.run_id === next.run_id) void openPanel();
      } : null,
    });
  }

  function renderJob(next,refresh = false) {
    const changed = refresh || job?.state !== next?.state || job?.run_id !== next?.run_id || job?.reason !== next?.reason;
    job = next;
    const status = jobLabels[next?.state] || '';
    const model = next?.model?.model;
    publishSpecialist(next,status,model);
    updateBuildProgress();
    if (!changed) return;
    dockStatus.textContent = next?.state === 'running' ? '' : status;
    if (next) {
      report(status + (model ? ' · ' + model : '') + (next.reason && next.state === 'failed' ? '. ' + next.reason : ''));
    }
    updateControls();
  }

  function verifyJob(next,conversation) {
    if (!next || !Number.isSafeInteger(next.run_id) || next.run_id < 1 || next.conversation_id !== conversation
      || !canvasIdPattern.test(next.canvas_id) || !Object.hasOwn(jobLabels,next.state)
      || (next.state === 'ready' && (!Number.isSafeInteger(next.revision) || next.revision < 1))) {
      throw new Error('The saved Coding Agent status could not be verified.');
    }
  }

  async function advance(next,version,conversation,refreshStatus = false) {
    if (suspended || version !== epoch || conversation !== scope() || !matches()) return;
    verifyJob(next,conversation);
    if (job?.run_id === next.run_id && !activeJob(job) && activeJob(next)) return;
    const newlyTerminal = !activeJob(next) && (job?.run_id !== next.run_id || activeJob(job));
    lastBuildCheck = Date.now();
    renderJob(next,refreshStatus);
    if (next.state === 'queued' && (ticked?.run !== next.run_id || ticked.retryAt <= Date.now())) {
      const attempt = {run:next.run_id,retryAt:Infinity};
      ticked = attempt;
      const retryLater = () => { attempt.retryAt = Date.now() + 4000; };
      void api('tick',{run_id:next.run_id},conversation).then(retryLater,retryLater);
    }
    clearTimeout(poll);
    if (activeJob(next)) {
      poll = setTimeout(() => void pollJob(next.run_id,version,conversation),900);
    } else if (newlyTerminal) {
      window.dispatchEvent(new Event('vp3-coding-usage-change'));
      if (next.state === 'ready') {
        try {
          scheduleFooterRefresh();
          if (version === epoch && editing() && observedJob !== next.run_id && !openController && !sourceEditor?.dirty() && !sourceEditor?.pending() && !selectedSourceContext && !workflowEditor?.busy()) {
            const retest=workflowEditor?.prepareJobResult(next);
            void refreshList(version).catch(() => {});
            await openSaved(next.canvas_id,next.revision,false);
            if(retest)void workflowEditor.retest(next);
          }
        } catch (error) {
          if (version === epoch && shown()) report(error.message);
        }
      }
    }
    workflowEditor?.jobStopped(next);
  }

  async function pollJob(runId,version,conversation) {
    if (suspended || version !== epoch || conversation !== scope() || !matches() || job?.run_id !== runId || !activeJob(job)) return;
    try {
      const data = await api('job',{run_id:runId},conversation);
      if (job?.run_id !== runId) return;
      const recovered = pollingError;
      pollingError = false;
      pollingBlocked = false;
      await advance(data.job,version,conversation,recovered);
    } catch (error) {
      if (suspended || version !== epoch || job?.run_id !== runId || !activeJob(job)) return;
      window.VP3ChatSpecialists?.remove(specialistKey(job));
      pollingBlocked = [401,403,404,419,422].includes(error.status);
      if (!pollingError || pollingBlocked) report(error.message + (pollingBlocked ? ' Restore access, then check build status.' : ' Checking again…'));
      pollingError = true;
      updateControls();
      if (!pollingBlocked) poll = setTimeout(() => void pollJob(runId,version,conversation),2600);
    }
  }

  function acceptAdmission(attempt,next,version,conversation) {
    if (attempt !== lastIntent || version !== epoch || conversation !== scope() || !matches()) return;
    verifyJob(next,conversation);
    lastIntent = null;
    if(attempt.sourceContext&&attempt.sourceContext===selectedSourceContext)clearSourceContext();
    if (attempt.text && prompt.value.trim() === attempt.text) prompt.value = '';
    if (attempt.command && input?.value === attempt.command) {
      input.value = '';
      input.dispatchEvent(new Event('input'));
    }
    return advance(next,version,conversation);
  }

  async function recoverAdmission(attempt,version,conversation) {
    if (!attempt || attempt !== lastIntent || !matches()) return;
    let data;
    try { data = await api('recover',{request_id:attempt.id},conversation); }
    catch (error) {
      if ([401,403,404,419,422].includes(error.status)) attempt.blocked = true;
      throw error;
    }
    if (attempt !== lastIntent || version !== epoch || conversation !== scope() || !matches()) return;
    if (data.job) await acceptAdmission(attempt,data.job,version,conversation);
    else {
      attempt.pending = false;
      report('No saved run was found for that request. Your instruction was kept; you can submit it again.');
    }
    updateControls();
  }

  async function checkBuildStatus() {
    if (checkingBuild || locked || !matches()) return;
    const version = epoch,conversation = scope(),attempt = lastIntent,running = job;
    checkingBuild = true;
    updateControls();
    try {
      if (attempt?.pending) await recoverAdmission(attempt,version,conversation);
      else if (running) {
        const data = await api('job',{run_id:running.run_id},conversation);
        if (version === epoch && job?.run_id === running.run_id) {
          pollingError = false;
          pollingBlocked = false;
          await advance(data.job,version,conversation,true);
        }
      } else await resume();
    } catch (error) {
      if (version === epoch && matches()) report(error.message);
    } finally {
      if (version === epoch) { checkingBuild = false; updateControls(); }
    }
  }

  async function retrySavedRequest() {
    const previous = job,version = epoch,conversation = scope();
    if (!previous?.can_retry || activeJob(previous) || locked || checkingBuild || lastIntent?.pending || !matches()) return;
    const intent = JSON.stringify([conversation,'retry',previous.run_id]);
    if (lastIntent?.intent !== intent) lastIntent = {intent,id:crypto.randomUUID()};
    const attempt = lastIntent;
    attempt.pending = true;
    locked = true;
    updateControls();
    try {
      const data = await api('retry',{run_id:previous.run_id,request_id:attempt.id},conversation);
      await acceptAdmission(attempt,data.job,version,conversation);
    } catch (error) {
      if (version !== epoch || !matches()) return;
      if (error.status) attempt.pending = false;
      report(error.message);
      if (attempt.pending) {
        try { await recoverAdmission(attempt,version,conversation); }
        catch (_) { if (version === epoch) report('The retry has not been confirmed. Check build status to recover the saved run.'); }
      }
    } finally {
      if (version === epoch) { locked = false; updateControls(); }
    }
  }

  async function editSavedRequest() {
    const previous = job,version = epoch,conversation = scope(),selected = current,selection = load;
    if (!previous || locked || checkingBuild || activeJob(previous) || !matches() || !editing()) return;
    if (prompt.value.trim()) { report('Your unsent instruction was kept. Clear it before loading the saved request.'); return; }
    locked = true;
    updateControls();
    try {
      const data = await api('saved_request',{run_id:previous.run_id},conversation);
      if (version !== epoch || conversation !== scope() || !matches() || !editing()
        || current !== selected || load !== selection || job?.run_id !== previous.run_id) return;
      const request = data.request;
      if (!request || typeof request.prompt !== 'string' || request.prompt.length > 8000 || !['build','repair'].includes(request.operation)) throw new Error('The saved instruction could not be verified.');
      if (request.operation === 'repair' && current?.canvas.id !== request.canvas_id
          && !await openSaved(request.canvas_id,undefined,false)) return;
      if (version !== epoch || conversation !== scope() || !matches() || !editing() || prompt.value.trim()) return;
      prompt.value = request.prompt;
      if (request.edit_context && current?.canvas.id===request.canvas_id && current?.revision.id===request.revision
          && request.edit_context.source_hash===current.revision.source_hash) {
        selectSourceContext(request.edit_context,({html:'HTML',css:'CSS',js:'JavaScript'}[request.edit_context.language]||'Source')+' selection · saved version '+request.revision);
      }
      prompt.focus();
      report('Saved request loaded. Edit it, then choose '+(request.operation === 'repair' ? 'Repair for the selected version.' : 'Build new.'));
    } catch (error) {
      if (version === epoch && matches()) report(error.message);
    } finally {
      if (version === epoch) { locked = false; updateControls(); }
    }
  }

  async function startEmail() {
    const conversation = scope();
    if (emailStart.hidden || locked || activeJob(job) || !conversation || !matches() || !shown()) return;
    const version = epoch;
    if (lastEmailStart?.version !== version || lastEmailStart.conversation !== conversation) {
      lastEmailStart = {version,conversation,id:crypto.randomUUID()};
    }
    locked = true;
    updateControls();
    try {
      const result = await api('start_email',{request_id:lastEmailStart.id},conversation);
      if (version !== epoch || !matches() || conversation !== scope()) return;
      if (!Number.isSafeInteger(result.preparation_run_id) || result.preparation_run_id < 1) {
        throw new Error('The Coding Agent request could not be verified.');
      }
      lastEmailStart = null;
      emailStart.hidden = true;
      report(['failed','cancelled'].includes(result.status) ? 'The email board preparation stopped. Check Top of Mind for its status.' :
        'The Coding Agent will prepare a private email deadline board. Follow its progress in Top of Mind.');
    } catch (error) {
      if (version === epoch && shown()) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function run(action,text,command) {
    text = text.trim();
    if (/^\/commands$/i.test(text)) { openCommandLibrary();return; }
    if (!text && action === 'repair' && previewFailed()) text = 'Fix this app so it opens and works in Canvas.';
    if (sourceEditor?.dirty()||sourceEditor?.pending()) {report('Save or discard the source edits before starting a Coding Agent request.');return;}
    if(workflowEditor?.busy()){report('Finish or stop the workflow check before starting another Coding Agent request.');return;}
    if (!text || text.length > 8000 || locked || activeJob(job) || lastIntent?.pending || !matches() || !chat()?.isReady()) {
      if (shown() && !text) report('Describe what you want the Coding Agent to make or change.');
      return;
    }
    const version = epoch;
    let conversation = scope();
    const fields = {prompt:text};
    let attempt = null;
    const requestedCommands = promptCommands(text);
    if (action === 'repair') {
      if (!current?.revision) return;
      fields.canvas_id = current.canvas.id;
      fields.revision = current.revision.id;
      if (selectedSourceContext?.canvas===current.canvas.id&&selectedSourceContext.revision===current.revision.id)fields.edit_context=selectedSourceContext.context;
      if (previewFailed()) fields.diagnostic = 'The isolated browser preview failed. ' + failedPreview.diagnostic;
    }
    locked = true;
    updateControls();
    try {
      if (!conversation) {
        if (shown()) chat().showChatView();
        conversation = await chat().ensureConversation();
        if (version !== epoch || !matches()) return;
      }
      openPanel();
      if (version !== epoch || !shown()) return;
      if (!conversation) throw new Error('Wait for Chat to finish loading, then try again.');
      if (requestedCommands.size) {
        if (!commandCatalog) await refreshList(version);
        if (version !== epoch || !editing() || !matches()) return;
        const available = (commandCatalog ?? []).map(([name]) => name).filter(name => requestedCommands.has(name));
        if (available.length !== requestedCommands.size) throw new Error('Some Canvas commands in this request are unavailable. Check /commands.');
        fields.commands = available;
      }
      const intent = JSON.stringify([conversation,action,fields]);
      if (lastIntent?.intent !== intent) lastIntent = {intent,id:crypto.randomUUID()};
      attempt = lastIntent;
      Object.assign(attempt,{pending:true,text,command,sourceContext:fields.edit_context?selectedSourceContext:null});
      fields.request_id = lastIntent.id;
      const data = await api(action,fields,conversation);
      await acceptAdmission(attempt,data.job,version,conversation);
    } catch (error) {
      if (version === epoch) {
        if (attempt && error.status) attempt.pending = false;
        if (!shown() && chat()?.isReady()) void openPanel();
        report(error.message);
        if (attempt?.pending) {
          report('Connection interrupted. Checking whether the saved request was accepted…');
          try { await recoverAdmission(attempt,version,conversation); }
          catch (_) { if (version === epoch) report('The build has not been confirmed. Check build status to recover the saved run.'); }
        }
      }
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function stopJob() {
    const running = job;
    const version = epoch;
    const conversation = scope();
    if (!activeJob(running)) return;
    stop.disabled = true;
    try {
      const data = await api('stop',{run_id:running.run_id},conversation);
      if (job?.run_id === running.run_id) await advance(data.job,version,conversation);
    } catch (error) {
      if (version === epoch && job?.run_id === running.run_id) report(error.message);
    } finally {
      if (version === epoch) stop.disabled = false;
    }
  }

  async function testPreview() {
    if (!current?.revision || test.disabled || !matches()) return;
    if(current.revision.workflow_checks?.length&&workflowEditor){await workflowEditor.start(false);return;}
    setEditorTab('preview');
    locked = true;
    updateControls();
    report(current.email_source ? 'Checking the email deadlines with sample data…' :
      'Starting an isolated test with a copy of the canvas data…');
    startPreview(true);
  }

  async function recordTest(candidate,emailWorkflowChecked) {
    const connected = current.email_source === true;
    if (connected && !emailWorkflowChecked) {
      failPreview(candidate,'email_workflow');
      return;
    }
    try {
      await api('test',{canvas_id:candidate.canvasId,revision:candidate.revision,state_revision:candidate.stateRevision,
        ...(connected ? {email_fixture_checked:emailWorkflowChecked} : {}),
        ...(candidate.imageToken ? {expected_image:candidate.imageToken} : {})},candidate.scope);
      if (!validFrame(candidate)) return;
      if (previewFailed()) failedPreview = null;
      browserTest = {canvas:candidate.canvasId,revision:candidate.revision,snapshot:candidate.stateRevision,imageToken:candidate.imageToken};
      requestArtwork();
      report(connected ? 'The email deadline test passed with sample data.' :
        'Preview loaded successfully. You can try the app here before using this version.');
    } catch (error) {
      if (validFrame(candidate)) report(error.message);
    } finally {
      if (validFrame(candidate)) {
        candidate.testing = false;
        locked = false;
        if (connected) startPreview();
        updateControls();
      }
    }
  }

  async function applyVersion() {
    if (apply.disabled || !current?.revision || !frame?.ready || !validFrame(frame) || !matches()) return;
    const version = epoch;
    const {canvas,revision} = current;
    locked = true;
    updateControls();
    try {
      const imageToken = frame.imageToken;
      const data = await api('apply',{canvas_id:canvas.id,revision:revision.id,expected_active_revision:canvas.active_revision,
        ...(imageToken ? {expected_image:imageToken} : {})});
      if (version !== epoch || !shown()) return;
      if (job?.canvas_id === canvas.id && job.revision === revision.id) renderJob(null);
      renderCanvas(data);
      requestArtwork();
      report('This version is active. Your saved app data was kept.');
      scheduleFooterRefresh();
    } catch (error) {
      if (version === epoch) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function setAppHidden() {
    const selected = current;
    const version = epoch;
    const conversation = scope();
    if (!selected?.revision || locked || !editing() || !matches()) return;
    const hidden = selected.canvas.hidden !== true;
    locked = true;
    updateControls();
    try {
      const data = await api('set_hidden',{canvas_id:selected.canvas.id,hidden},conversation);
      if (version !== epoch || selected !== current || conversation !== scope() || !editing() || !matches()) return;
      if (data.canvas?.id !== selected.canvas.id || data.canvas?.hidden !== hidden) {
        throw new Error('The app’s shortcut visibility could not be verified.');
      }
      selected.canvas.hidden = hidden;
      await refreshList(version);
      if (version === epoch && selected === current && editing()) {
        report(hidden ? 'Hidden from shortcuts. You can still open this app from Saved canvases.' : 'App shortcuts are visible.');
      }
    } catch (error) {
      if (version === epoch && selected === current && editing()) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function deleteCanvas() {
    const version = epoch;
    if (locked || !current || activeJob(job) || !matches()) return;
    locked = true;
    updateControls();
    try {
      const id = current.canvas.id;
      await api('delete',{canvas_id:id,delete_folder:deleteFolder.checked});
      if (version !== epoch || !shown()) return;
      if (job?.canvas_id === id) renderJob(null);
      renderCanvas(null);
      for (const group of [appIcons,editorAppIcons]) {
        group.querySelector(`[data-canvas-id="${id}"]`)?.remove();
        group.hidden = group.childElementCount === 0;
      }
      await refreshList(version);
      if (version === epoch && shown()) report('Canvas deleted.');
    } catch (error) {
      if (version === epoch) report(error.message);
    } finally {
      if (version === epoch) {
        locked = false;
        updateControls();
      }
    }
  }

  async function resume() {
    const version = epoch;
    const conversation = scope();
    if (suspended || document.hidden || !conversation || !matches() || !chat()?.isReady() || chat()?.isRestoring() || locked || activeJob(job)) return;
    if (lastIntent?.pending) {
      if (!lastIntent.blocked && !checkingBuild) void checkBuildStatus();
      return;
    }
    if (resuming) { resumeAgain = true; return; }
    resuming = true;
    try {
      const data = await api('resume',{},conversation);
      if (!suspended && !document.hidden && version === epoch && conversation === scope() && matches() && !locked && !activeJob(job)
        && data.job?.conversation_id === conversation) {
        observedJob = data.job.run_id;
        await advance(data.job,version,conversation);
      }
    } catch (_error) { /* Saved canvases and explicit builds remain available. */ }
    finally {
      resuming = false;
      if (resumeAgain) { resumeAgain = false; scheduleResume(); }
    }
  }

  function scheduleResume() {
    clearTimeout(resumeTimer);
    if (!suspended && !document.hidden) resumeTimer = setTimeout(() => void resume(),250);
  }
  function watchResume() {
    if (!resumePoll) resumePoll = setInterval(() => void resume(),15000);
  }

  picker.addEventListener('change',() => void openSaved(picker.value));
  versions.addEventListener('change',() => void openSaved(current?.canvas.id,Number(versions.value)));
  chatForm?.addEventListener('submit',event => {
    if (/^\s*\/commands\s*$/i.test(input?.value ?? '')) {
      event.preventDefault();
      event.stopImmediatePropagation();
      if (!matches() || !chat()?.isReady()) return;
      input.value = '';
      input.dispatchEvent(new Event('input'));
      openCommandLibrary();
      return;
    }
    const command = input?.value.match(/^\s*\/build\s+([\s\S]+)$/i);
    if (!command) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    if (!matches() || !chat()?.isReady()) return;
    void run('build',command[1],input.value);
  },true);
  window.addEventListener('vp3:chat-canvas-view-changed',() => {
    panel.hidden = !shown();
    launch.setAttribute('aria-expanded',String(shown()));
    if (!shown()) {
      closeComparison();
      delete thread.dataset.codingMode;
      cancelOpen();
      resetLibraryImagePicker();
      reportApp('');
      if (frame?.testing) locked = false;
      commandSettingsRequest?.abort();
      publicationRequest?.abort();
      publicationRequest = null;
      profileCanvasRequest?.abort();
      profileCanvasRequest = null;
      cancelPublication(false);
      stopSourceRefreshes();
      closePreview();
      updateControls();
    }
  });
  function resetContext() {
    workflowEditor?.cancel();
    liveSource?.clear();
    sourceEditor?.clear(Number(window.STONEFELLOW_CHAT?.userId)!==owner||String(window.STONEFELLOW_CHAT?.workingAttentionBinding||'')!==pageBinding);
    closeComparison();
    closeArtworkFrame();
    artworkAttempts.clear();
    epoch++;
    cancelOpen();
    appTarget = null;
    chatPosition = null;
    clearTimeout(poll);
    clearTimeout(resumeTimer);
    clearTimeout(footerTimer);
    locked = false;
    pollingError = false;
    pollingBlocked = false;
    checkingBuild = false;
    lastBuildCheck = 0;
    lastIntent = null;
    lastEmailStart = null;
    emailStart.hidden = true;
    failedPreview = null;
    ticked = null;
    observedJob = null;
    browserTest = null;
    prompt.value = '';
    renderCanvas(null);
    renderJob(null);
    picker.replaceChildren();
    renderAppIcons([]);
    report('');
  }
  function updateParticipantScope() {
    resetContext();
    dock.hidden = participantContext();
    if (shown()) chat()?.showChatView();
    if (!dock.hidden) { void resume(); scheduleFooterRefresh(); }
  }
  window.addEventListener('stonefellow:chat-context-changing',resetContext);
  window.addEventListener('vp3:participant-selection-changed',updateParticipantScope);
  window.addEventListener('vp3:participant-task-context',event => {
    participantActor = Number(event.detail?.participant_id) || 0;
    updateParticipantScope();
  });
  window.addEventListener('vp3:chat-context-ready',() => { void resume(); scheduleFooterRefresh(); });
  window.addEventListener('vp3:canvas-artwork-updated',scheduleFooterRefresh);
  window.addEventListener('vp3:canvas-artwork-updated',event => {
    const {artwork,canvasId,ownerId,agentId,conversationId,binding} = event.detail || {};
    if (!artwork || typeof artwork.icon_url !== 'string' || !shown() || !matches() || canvasId !== current?.canvas?.id
      || artwork.id !== canvasId || ownerId !== owner || agentId !== agent || conversationId !== scope()
      || binding !== String(window.STONEFELLOW_CHAT?.workingAttentionBinding || '')) return;
    current.canvas.icon_url = artwork.icon_url;
    current.canvas.icon_hash = artwork.icon_hash;
    workspaceTitle.replaceChildren(canvasArt(current.canvas),document.createTextNode(current.canvas.title));
  });
  window.addEventListener('vp3:canvas-list-updated',scheduleFooterRefresh);
  window.addEventListener('vp3:chat-history-updated',scheduleResume);
  window.addEventListener('storage',event => {
    if (event.key !== commandChangesKey || !event.newValue) return;
    try {
      const change = JSON.parse(event.newValue);
      if (change.owner === owner && change.agent === agent && change.canvasId === current?.canvas?.id) {
        current.hasVerifiedGrants = true;
        clearPrivateAppState(current);
        setCommandSettings(null);
        stopSourceRefreshes();
        closePreview();
        void loadCommandSettings();
      }
    } catch (_error) { /* Ignore unrelated storage events. */ }
  });
  window.addEventListener('focus',() => {
    scheduleResume();
    scheduleFooterRefresh();
    if (current?.hasVerifiedGrants) void loadCommandSettings();
    if (editing() && publishing.open && !locked && !publicationIntent) void loadPublication();
  });
  document.addEventListener('visibilitychange',() => {
    updateBuildProgress();
    if (!document.hidden) {
      scheduleResume();
      scheduleFooterRefresh();
      if (current?.hasVerifiedGrants) void loadCommandSettings();
      else if (shown() && (current?.email_source || declaresGmail())) startPreview();
    } else if (current?.email_source || declaresGmail()) {
      if (frame?.testing) locked = false;
      stopSourceRefreshes();
      closePreview();
      updateControls();
    }
  });
  window.addEventListener('pagehide',() => {
    liveSource?.clear();
    closeComparison();
    suspended = true;
    resetLibraryImagePicker();
    closeArtworkFrame();
    clearTimeout(footerTimer);
    listRequest++;
    editorListRequest++;
    clearTimeout(resumeTimer);
    clearInterval(resumePoll);
    clearTimeout(poll);
    resumePoll = 0;
    if (current?.email_source || declaresGmail() || current?.library_image?.image) {
      if (frame?.testing) locked = false;
      stopSourceRefreshes();
      closePreview();
    }
  });
  window.addEventListener('pageshow',() => {
    suspended = false;
    watchResume();
    scheduleFooterRefresh();
    if (activeJob(job)) void pollJob(job.run_id,epoch,scope());
    else scheduleResume();
    if (shown() && current?.library_image?.image) void openSaved(current.canvas.id,current.revision.id,false);
    else if (current?.hasVerifiedGrants) void loadCommandSettings();
    else if (shown() && (current?.email_source || declaresGmail())) startPreview();
  });
  window.VP3CodingCanvasV1 = Object.freeze({open:openApp});
  const activityParams = new URLSearchParams(location.search);
  const activityCanvas = activityParams.get('canvas_id');
  const newCanvas = !activityCanvas && activityParams.get('canvas_new') === '1';
  const activityRevisionRaw = activityParams.get('canvas_revision');
  const activityRevision = activityRevisionRaw === null ? undefined : Number(activityRevisionRaw);
  let activityCanvasOpened = false;
  function openActivityCanvas() {
    if (activityCanvasOpened || (!activityCanvas && !newCanvas) || !matches() || !chat()?.isReady() || chat()?.isRestoring()) return;
    activityCanvasOpened = true;
    if (newCanvas) {
      void openPanel(false);
      return;
    }
    void openApp(activityCanvas, activityRevision);
  }
  window.addEventListener('vp3:chat-context-ready', openActivityCanvas);
  dock.hidden = participantContext();
  watchResume();
  void resume();
  scheduleFooterRefresh();
  updateControls();
  openActivityCanvas();
})();
