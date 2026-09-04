(() => {
  const cfg = window.STONEFELLOW_STEM_STUDIO;

  /*
   * Canvas-mode success flashes need to disappear even if another admin
   * control throws before admin.js reaches its generic notice handler.
   */
  document.querySelectorAll(
    '.notice:not(.error)'
  ).forEach(notice => {
    const delay = Math.max(
      800,
      Math.min(
        10000,
        Number(
          notice.dataset.autoDismiss ||
          2600
        )
      )
    );

    window.setTimeout(
      () => {
        if (!notice.isConnected) {
          return;
        }

        notice.classList.add(
          'notice-leaving'
        );

        window.setTimeout(
          () => notice.remove(),
          220
        );
      },
      delay
    );
  });

  /*
   * Empty projects are valid in v47+. Do not abort the Studio just because
   * there are no stems yet; New Project, Import Media, Track Library,
   * transport setup and project menus must still initialize.
   */
  if (!cfg || !Array.isArray(cfg.stems)) return;

  const playButton = document.getElementById('stemPlayButton');
  const studioRecordButton = document.getElementById('studioRecordButton');
  const studioAudioInput = document.getElementById('studioAudioInput');
  const studioInputAccess = document.getElementById('studioInputAccess');
  const studioMonitorButton = document.getElementById('studioMonitorButton');
  const studioInputMeter = document.getElementById('studioInputMeter');
  const studioRecordStatus = document.getElementById('studioRecordStatus');
  const currentTimeEl = document.getElementById('stemCurrentTime');
  const masterVolume = document.getElementById('stemMasterVolume');
  const masterValue = document.getElementById('stemMasterValue');

  const auxReturnA = document.getElementById('auxReturnA');
  const auxReturnB = document.getElementById('auxReturnB');
  const auxReturnAValue = document.getElementById('auxReturnAValue');
  const auxReturnBValue = document.getElementById('auxReturnBValue');

  const masterMeterBars = [
    ...document.querySelectorAll('[data-master-meter] i')
  ];
  const resetMixButton = document.getElementById('stemResetMix');

  const loopToggleButton = document.getElementById('stemLoopToggle');
  const loopClearButton = document.getElementById('stemLoopClear');

  const dawArrange = document.getElementById('dawArrange');
  const timelineSurface = document.getElementById('dawTimelineSurface');
  const ruler = document.getElementById('dawRuler');
  const rulerLines = document.getElementById('dawRulerLines');
  const playhead = document.getElementById('dawPlayhead');
  const recordingRegion = document.getElementById('dawRecordingRegion');
  const loopSelection = document.getElementById('dawLoopSelection');
  const loopLabel = document.getElementById('dawLoopLabel');
  const markerLane = document.getElementById('dawMarkerLane');

  const timelineZoomOut = document.getElementById('timelineZoomOut');
  const timelineZoomIn = document.getElementById('timelineZoomIn');
  const timelineZoomValue = document.getElementById('timelineZoomValue');
  const addTimelineMarker = document.getElementById('addTimelineMarker');
  const addTimelineRegion = document.getElementById('addTimelineRegion');
  const regionNoteDialog = document.getElementById('regionNoteDialog');
  const regionNoteRange = document.getElementById('regionNoteRange');
  const regionNoteText = document.getElementById('regionNoteText');
  const shareRegionNoteButton = document.getElementById('shareRegionNote');
  const cancelRegionNote = document.getElementById('cancelRegionNote');
  const cancelRegionNoteTop = document.getElementById('cancelRegionNoteTop');

  const sessionTempoInput = document.getElementById('sessionTempo');
  const resetSessionTempo = document.getElementById('resetSessionTempo');
  const timelineSnapToggle = document.getElementById('timelineSnapToggle');

  const studioMainMenuToggle = document.getElementById('studioMainMenuToggle');
  const studioMainMenu = document.getElementById('studioMainMenu');
  const studioSaveButton = document.getElementById('studioSaveButton');
  const studioSaveAsButton = document.getElementById('studioSaveAsButton');
  const studioSaveStatus = document.getElementById('studioSaveStatus');
  const songInfoToggle = document.getElementById('songInfoToggle');
  const songInfoMenu = document.getElementById('songInfoMenu');

  const exportAudioDialog = document.getElementById('exportAudioDialog');

  const openProjectDialog = document.getElementById('openProjectDialog');
  const openProjectSearch = document.getElementById('openProjectSearch');
  const openProjectGrid = document.getElementById('openProjectGrid');

  const loadSongDialog = document.getElementById('loadSongDialog');
  const loadSongSearch = document.getElementById('loadSongSearch');
  const loadSongList = document.getElementById('loadSongList');

  const recordingSaveDialog = document.getElementById('recordingSaveDialog');
  const recordingSaveTrack = document.getElementById('recordingSaveTrack');
  const recordingSaveStats = document.getElementById('recordingSaveStats');
  const recordingSaveSignal = document.getElementById('recordingSaveSignal');
  const recordingSaveName = document.getElementById('recordingSaveName');
  const saveRecordingButton = document.getElementById('saveRecordingButton');
  const discardRecordingButton = document.getElementById('discardRecordingButton');

  const audioPermissionDialog = document.getElementById('audioPermissionDialog');
  const audioPermissionMessage = document.getElementById('audioPermissionMessage');
  const audioPermissionDetail = document.getElementById('audioPermissionDetail');
  const retryAudioPermission = document.getElementById('retryAudioPermission');

  const newStudioProjectDialog = document.getElementById('newStudioProjectDialog');
  const newStudioProjectName = document.getElementById('newStudioProjectName');
  const newStudioProjectTempo = document.getElementById('newStudioProjectTempo');
  const newStudioProjectSignature = document.getElementById('newStudioProjectSignature');
  const createStudioProjectButton = document.getElementById('createStudioProjectButton');

  const studioImportDialog = document.getElementById('studioImportDialog');
  const studioImportStatus = document.getElementById('studioImportStatus');
  const studioImportFileStatus = document.getElementById('studioImportFileStatus');
  const studioImportProgress = document.getElementById('studioImportProgress');
  const studioImportSingle = document.getElementById('studioImportSingle');
  const studioImportMultiple = document.getElementById('studioImportMultiple');

  const openTrackLibrary = document.getElementById('openTrackLibrary');
  const closeTrackLibrary = document.getElementById('closeTrackLibrary');
  const trackLibraryDrawer = document.getElementById('trackLibraryDrawer');
  const trackLibraryBackdrop = document.getElementById('trackLibraryBackdrop');
  const trackLibrarySearch = document.getElementById('trackLibrarySearch');
  const trackLibraryCategory = document.getElementById('trackLibraryCategory');
  const trackLibraryList = document.getElementById('trackLibraryList');
  const trackLibraryCategoryButton = document.getElementById('trackLibraryCategoryButton');
  const trackLibraryCategoryMenu = document.getElementById('trackLibraryCategoryMenu');
  const trackRoutePopover = document.getElementById('trackRoutePopover');
  const studioTrackContextMenu = document.getElementById('studioTrackContextMenu');

  const openTrackInspector = document.getElementById('openTrackInspector');
  const closeTrackInspector = document.getElementById('closeTrackInspector');
  const trackInspector = document.getElementById('trackInspector');
  const trackInspectorBackdrop = document.getElementById('trackInspectorBackdrop');
  const inspectorTrackName = document.getElementById('inspectorTrackName');
  const inspectorTrackMeta = document.getElementById('inspectorTrackMeta');
  const inspectorTrackState = document.getElementById('inspectorTrackState');
  const inspectorTrackNameInput = document.getElementById('inspectorTrackNameInput');
  const inspectorTrackRole = document.getElementById('inspectorTrackRole');
  const inspectorSaveTrackDetails = document.getElementById('inspectorSaveTrackDetails');
  const inspectorTrackInput = document.getElementById('inspectorTrackInput');
  const inspectorTrackBus = document.getElementById('inspectorTrackBus');
  const inspectorVolume = document.getElementById('inspectorVolume');
  const inspectorVolumeValue = document.getElementById('inspectorVolumeValue');
  const inspectorPan = document.getElementById('inspectorPan');
  const inspectorPanValue = document.getElementById('inspectorPanValue');
  const inspectorTrim = document.getElementById('inspectorTrim');
  const inspectorTrimValue = document.getElementById('inspectorTrimValue');
  const inspectorSendA = document.getElementById('inspectorSendA');
  const inspectorSendAValue = document.getElementById('inspectorSendAValue');
  const inspectorSendB = document.getElementById('inspectorSendB');
  const inspectorSendBValue = document.getElementById('inspectorSendBValue');
  const inspectorArm = document.getElementById('inspectorArm');
  const inspectorMute = document.getElementById('inspectorMute');
  const inspectorSolo = document.getElementById('inspectorSolo');
  const inspectorAutomation = document.getElementById('inspectorAutomation');
  const inspectorPluginCount = document.getElementById('inspectorPluginCount');
  const inspectorPluginList = document.getElementById('inspectorPluginList');
  const inspectorAddPlugin = document.getElementById('inspectorAddPlugin');
  const inspectorOpenPluginRack = document.getElementById('inspectorOpenPluginRack');
  const inspectorClipName = document.getElementById('inspectorClipName');
  const inspectorClipGain = document.getElementById('inspectorClipGain');
  const inspectorClipGainValue = document.getElementById('inspectorClipGainValue');
  const inspectorFadeIn = document.getElementById('inspectorFadeIn');
  const inspectorFadeInValue = document.getElementById('inspectorFadeInValue');
  const inspectorFadeOut = document.getElementById('inspectorFadeOut');
  const inspectorFadeOutValue = document.getElementById('inspectorFadeOutValue');
  const inspectorClipMute = document.getElementById('inspectorClipMute');
  const inspectorAutoCrossfade = document.getElementById('inspectorAutoCrossfade');
  const inspectorRecordingState = document.getElementById('inspectorRecordingState');
  const recordCountInBars = document.getElementById('recordCountInBars');
  const recordMetronomeToggle = document.getElementById('recordMetronomeToggle');
  const studioMetronomeButton = document.getElementById('studioMetronomeButton');
  const studioMetronomeMenu = document.getElementById('studioMetronomeMenu');
  const studioMetronomeToggle = document.getElementById('studioMetronomeToggle');
  const studioMetronomeCountIn = document.getElementById('studioMetronomeCountIn');
  const studioMetronomeTempo = document.getElementById('studioMetronomeTempo');
  const recordPunchToggle = document.getElementById('recordPunchToggle');
  const recordPunchFromLoop = document.getElementById('recordPunchFromLoop');
  const recordPunchRange = document.getElementById('recordPunchRange');
  const inspectorRecordButton = document.getElementById('inspectorRecordButton');
  const inspectorStopRecordButton = document.getElementById('inspectorStopRecordButton');

  const trackList = document.getElementById('dawTrackList');
  const arrangeLanes = document.getElementById('dawArrangeLanes');
  const mixerScroll = document.getElementById('dawMixerScroll');
  const studio = document.getElementById('stemStudio');
  const pluginRackHandle = document.getElementById('pluginRackHandle');
  const addMixerBus = document.getElementById('addMixerBus');
  const customBusAnchor = document.getElementById('customBusAnchor');

  const addBusDialog = document.getElementById('addBusDialog');
  const newBusName = document.getElementById('newBusName');
  const createMixerBusButton = document.getElementById('createMixerBusButton');

  const pluginDirectoryDialog = document.getElementById('pluginDirectoryDialog');
  const pluginDirectoryTrack = document.getElementById('pluginDirectoryTrack');
  const pluginEditor = document.getElementById('pluginEditor');
  const pluginEditorTitle = document.getElementById('pluginEditorTitle');
  const pluginEditorControls = document.getElementById('pluginEditorControls');
  const pluginBypassButton = document.getElementById('pluginBypassButton');
  const pluginRemoveButton = document.getElementById('pluginRemoveButton');

  const masterBusDialog = document.getElementById('masterBusDialog');
  const mixSaveDialog = document.getElementById('mixSaveDialog');
  const savedMixList = document.getElementById('savedMixList');
  const stemMixName = document.getElementById('stemMixName');
  const saveStemMixButton = document.getElementById('saveStemMix');

  let duration = Number(cfg.duration || 0);
  const trackId = Number(cfg.trackId || 0);
  const projectTitle = String(
    cfg.projectTitle ||
    'Stonefellow Project'
  ).trim() || 'Stonefellow Project';
  const userId = Number(cfg.userId || 0);
  const sourceTempo = Math.max(
    40,
    Math.min(300,Number(cfg.sourceTempo || 120))
  );
  const sessionTimeSignature =
    String(cfg.timeSignature || '4/4');
  const stemMediaBase =
    String(cfg.stemMediaBase || '/stem-media-v34.php?id=');
  const waveformEndpoint =
    String(cfg.waveformEndpoint || '');
  const projectEndpoint =
    String(cfg.projectEndpoint || '');

  let sessionTempo = sourceTempo;
  let libraryClips = [];
  let openRouteStem = null;

  let editSnapMode = 'grid';
  let selectedArrangementClip = null;
  let contextMenuStemId = 0;

  const waveformQueue = [];
  let waveformWorkerRunning = false;
  let waveformDecodeContext = null;

  let touchPinchStartDistance = 0;
  let touchPinchStartZoom = 1;
  let touchPinchAnchorTime = 0;

  let recordingStream = null;
  let recordingInputSource = null;
  let recordingInputAnalyser = null;
  let recordingMeterData = null;
  let recordingMonitorGain = null;
  let recordingMeterSink = null;
  let recordingProcessor = null;
  let recordingProcessorSink = null;
  let recordingMeterFrame = 0;
  let recordingActive = false;
  let recordingStopping = false;
  let recordingMonitorEnabled = false;
  let recordingArmedStemId = 0;
  let recordingId = '';
  let recordingChunkIndex = 0;
  let recordingPendingBytes = [];
  let recordingPendingByteLength = 0;
  let recordingUploadChain = Promise.resolve();
  let recordingUploadError = null;
  let recordingCapturedBytes = 0;
  let recordingSignalPeak = 0;
  let recordingPendingSave = null;
  let recordingLiveElement = null;
  let recordingLiveCanvas = null;
  let recordingLiveMins = [];
  let recordingLiveMaxs = [];
  let recordingLiveLastTimelineResize = 0;
  let recordingOriginalDuration = 0;
  let recordingStartTimeline = 0;
  let recordingStartWallTime = 0;
  let recordingChannelCount = 2;
  let recordingSampleRate = 48000;
  let recordingStartedTransport = false;
  let recordingLiveOnlyMode = false;

  let recordingCountInBars = 0;
  let recordingMetronomeEnabled = false;
  let recordingPunchEnabled = false;
  let recordingPunchStart = 0;
  let recordingPunchEnd = 0;
  let recordingCountInActive = false;
  let recordingCountInCancel = false;
  let metronomeLastBeat = -1;
  let inputClipLatched = false;

  const recordingSettingsStorageKey =
    `stonefellow:stem-studio:recording:${userId}:${trackId}`;
  const recordingChunkTargetBytes = 512 * 1024;
  const recordingInputStorageKey =
    `stonefellow:stem-studio:input:${userId}`;

  const pluginImportVersion = String(cfg.pluginImportVersion || 'base');
  const localStateKey =
    `stonefellow:stem-studio:state:${userId}:${trackId}:${pluginImportVersion}`;

  let localPersistenceReady = false;
  let localSaveTimer = 0;
  let selectedStemId = 0;

  const undoStack = [];
  const undoLimit = 80;
  let undoBaseline = null;
  let undoBaselineJson = '';
  let undoGroupOpen = false;
  let undoGroupTimer = 0;
  let applyingUndo = false;

  const AudioContextClass = window.AudioContext || window.webkitAudioContext;

  let context = null;
  let busInput = null;
  let eqLow = null;
  let eqMid = null;
  let eqHigh = null;
  let compressor = null;
  let masterGain = null;
  let masterAnalyser = null;
  let masterLevelData = null;
  let masterLiveMixTap = null;

  let auxAInput = null;
  let auxAConvolver = null;
  let auxAReturnGain = null;

  let auxBInput = null;
  let auxBDelay = null;
  let auxBFeedback = null;
  let auxBReturnGain = null;

  const groupState = {
    vocals:{volume:1,muted:false,input:null,gain:null,analyser:null,data:null},
    rhythm:{volume:1,muted:false,input:null,gain:null,analyser:null,data:null},
    music:{volume:1,muted:false,input:null,gain:null,analyser:null,data:null}
  };

  const fixedPluginTargets = {
    master:{
      key:'master',
      kind:'master',
      label:'MASTER',
      plugins:normalizeTrackPlugins(cfg.masterPluginChain || []),
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    },
    'aux-a':{
      key:'aux-a',
      kind:'aux-a',
      label:'AUX A · ROOM',
      plugins:[{
        type:'reverb',
        enabled:true,
        params:{
          decay:1.9,
          size:1.0,
          damping:9000,
          mix:1
        }
      }],
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    },
    'aux-b':{
      key:'aux-b',
      kind:'aux-b',
      label:'AUX B · DELAY',
      plugins:[{
        type:'delay',
        enabled:true,
        params:{
          time:0.32,
          feedback:0.34,
          mix:1
        }
      }],
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    },
    'group-vocals':{
      key:'group-vocals',
      kind:'group',
      group:'vocals',
      label:'VOCALS',
      plugins:[],
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    },
    'group-rhythm':{
      key:'group-rhythm',
      kind:'group',
      group:'rhythm',
      label:'RHYTHM',
      plugins:[],
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    },
    'group-music':{
      key:'group-music',
      kind:'group',
      group:'music',
      label:'MUSIC',
      plugins:[],
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null
    }
  };

  let customBuses = [];

  let dryGain = null;
  let wetGain = null;
  let reverb = null;

  let playing = false;
  let position = 0;
  let startedAt = 0;
  let frame = 0;

  // Explicit seeks are coordinated. Normal animation frames never repeatedly
  // rewrite media.currentTime; doing that against HTTP range-backed MP3/WAV
  // streams can continuously restart decoders and produce static.
  let seekSerial = 0;
  let seekInProgress = false;

  let draggedStemId = 0;
  let dragSourceElement = null;
  let selectedMixId = 0;
  let selectedMixName = '';

  const saveSelectionKey =
    `stonefellow:stem-studio:saved-version:${userId}:${trackId}`;

  let pluginRackOpen = false;
  let pluginRackHeight = 348;
  let pluginTargetKey = '';
  let pluginEditIndex = -1;

  let loopStart = 0;
  let loopEnd = 0;
  let loopActive = false;

  let timelineZoom = 1;
  let timelineMarkers = [];
  let timelineRegions = [];

  let selectingLoop = false;
  let selectionPointerId = null;
  let selectionStartTime = 0;
  let selectionStartX = 0;
  let selectionDragged = false;
  let suppressSurfaceClickUntil = 0;

  let syncingVerticalScroll = false;

  const hasImportedMasterChain =
    Array.isArray(cfg.masterPluginChain) &&
    cfg.masterPluginChain.length > 0;

  const pluginState = {
    eq: !hasImportedMasterChain,
    compressor: !hasImportedMasterChain,
    reverb: false
  };

  const dbText = gain => {
    const value = Math.max(0, Number(gain || 0));
    if (value <= 0.0001) return '-∞ dB';
    const db = 20 * Math.log10(value);
    return `${db >= 0 ? '+' : ''}${db.toFixed(1)} dB`;
  };

  const panText = value => {
    const pan = Number(value || 0);
    if (Math.abs(pan) < 0.015) return 'C';
    return pan < 0
      ? `L${Math.round(Math.abs(pan) * 100)}`
      : `R${Math.round(pan * 100)}`;
  };

  const formatTime = seconds => {
    const safe = Math.max(0, Number(seconds || 0));
    const minutes = Math.floor(safe / 60);
    const secs = Math.floor(safe % 60).toString().padStart(2, '0');
    return `${minutes}:${secs}`;
  };

  const stems = cfg.stems.map(meta => {
    const leftRow = document.querySelector(
      `[data-stem-id="${Number(meta.id)}"]`
    );
    const mixer = document.querySelector(
      `[data-mixer-stem="${Number(meta.id)}"]`
    );
    const arrangeRow = document.querySelector(
      `[data-arrange-stem="${Number(meta.id)}"]`
    );
    const audio = leftRow?.querySelector('.stem-audio');
    const volume = mixer?.querySelector('[data-stem-volume]');
    const pan = mixer?.querySelector('[data-stem-pan]');
    const volumeOutput = mixer?.querySelector('[data-volume-value]');
    const panOutput = mixer?.querySelector('[data-pan-value]');
    const knob = mixer?.querySelector('[data-pan-knob]');
    const spectrumCanvas = mixer?.querySelector(
      '[data-track-spectrum]'
    );
    const spectrumCanvasContext = spectrumCanvas
      ? spectrumCanvas.getContext('2d')
      : null;

    const pluginList = mixer?.querySelector('[data-track-plugin-list]');
    const addPluginButton = mixer?.querySelector('[data-add-track-plugin]');

    const auxSendA = mixer?.querySelector('[data-aux-send="a"]');
    const auxSendB = mixer?.querySelector('[data-aux-send="b"]');
    const auxSendAValue = mixer?.querySelector('[data-aux-send-value="a"]');
    const auxSendBValue = mixer?.querySelector('[data-aux-send-value="b"]');

    const trim = mixer?.querySelector('[data-track-trim]');
    const trimValue = mixer?.querySelector('[data-track-trim-value]');
    const trimKnob = mixer?.querySelector('[data-trim-knob]');
    const inputSelect = mixer?.querySelector('[data-track-input]');
    const armButton = mixer?.querySelector('[data-track-arm]');
    const sidebarArmButton = leftRow?.querySelector('[data-sidebar-track-arm]');
    const groupSelect = mixer?.querySelector('[data-track-group]');
    const groupMenuButton = mixer?.querySelector('[data-track-group-menu]');

    const mainClipLayer = arrangeRow?.querySelector('[data-main-clip-layer]');

    const automationToggle = leftRow?.querySelector('[data-automation-toggle]');
    const automationLane = arrangeRow?.querySelector('[data-automation-lane]');
    const automationParameter = arrangeRow?.querySelector('[data-automation-parameter]');
    const automationGraph = arrangeRow?.querySelector('[data-automation-graph]');
    const automationPath = arrangeRow?.querySelector('[data-automation-path]');
    const automationPointsGroup = arrangeRow?.querySelector('[data-automation-points]');
    const automationDelete = arrangeRow?.querySelector('[data-automation-delete]');
    const automationClear = arrangeRow?.querySelector('[data-automation-clear]');
    const automationClearAll = arrangeRow?.querySelector('[data-automation-clear-all]');

    const muteButtons = [
      ...document.querySelectorAll(
        `[data-stem-id="${Number(meta.id)}"] [data-stem-mute],` +
        `[data-mixer-stem="${Number(meta.id)}"] [data-stem-mute]`
      )
    ];

    const soloButtons = [
      ...document.querySelectorAll(
        `[data-stem-id="${Number(meta.id)}"] [data-stem-solo],` +
        `[data-mixer-stem="${Number(meta.id)}"] [data-stem-solo]`
      )
    ];

    const stemSourceTempo = Math.max(
      40,
      Math.min(
        300,
        Number(meta.sourceTempo || sourceTempo)
      )
    );

    const stemTimelineRatio =
      stemSourceTempo /
      sourceTempo;

    const initialSourceStart = Math.max(
      0,
      Math.min(
        Math.max(
          0,
          Number(meta.duration || 0.05) - .01
        ),
        Number(meta.initialSourceStart || 0)
      )
    );

    const initialSourceEnd = Math.max(
      initialSourceStart + .01,
      Math.min(
        Math.max(
          .05,
          Number(meta.duration || 0.05)
        ),
        Number(
          meta.initialSourceEnd ??
          meta.duration ??
          .05
        )
      )
    );

    const initialTimelineLength = Math.max(
      0.05,
      (
        initialSourceEnd -
        initialSourceStart
      ) *
        stemTimelineRatio
    );

    return {
      ...meta,
      id: Number(meta.id),
      sourceTempo:stemSourceTempo,
      timelineRatio:stemTimelineRatio,
      pluginKey:`stem-${Number(meta.id)}`,
      kind:'stem',
      label:String(
        meta.stem_name ||
        meta.name ||
        `Stem ${Number(meta.id)}`
      ),
      leftRow,
      mixer,
      arrangeRow,
      audio,
      volume,
      pan,
      volumeOutput,
      panOutput,
      knob,
      spectrumCanvas,
      spectrumCanvasContext,
      pluginList,
      addPluginButton,
      auxSendA,
      auxSendB,
      auxSendAValue,
      auxSendBValue,
      trim,
      trimValue,
      trimKnob,
      inputSelect,
      armButton,
      sidebarArmButton,
      groupSelect,
      groupMenuButton,
      mainClipLayer,
      automationToggle,
      automationLane,
      automationParameter,
      automationGraph,
      automationPath,
      automationPointsGroup,
      automationDelete,
      automationClear,
      automationClearAll,
      selectedAutomationPoint:null,

      plugins: normalizeTrackPlugins(meta.pluginChain || []),
      pluginNodes: [],

      clips:[{
        id:`stem-${Number(meta.id)}-clip-1`,
        timelineStart:Math.max(
          0,
          Number(meta.offset || 0)
        ),
        timelineLength:
          initialTimelineLength,
        sourceStart:
          initialSourceStart,
        sourceEnd:
          initialSourceEnd,
        gainDb:0,
        muted:false,
        fadeIn:0,
        fadeOut:0
      }],
      activeClipId:'',
      trimDb:0,
      recordingInputDeviceId:'',
      group:
        String(meta.role || '').toLowerCase() === 'vocal'
          ? 'vocals'
          : ['drums','percussion','bass'].includes(
              String(meta.role || '').toLowerCase()
            )
            ? 'rhythm'
            : 'music',

      sends: {
        a: 0,
        b: 0
      },
      automation: {
        volume: [],
        pan: [],
        auxA: [],
        auxB: []
      },
      automationOpen: false,

      auxASendGain: null,
      auxBSendGain: null,

      muteButtons,
      soloButtons,
      muted: false,
      solo: false,
      clipGainNode:null,
      crossfadeAudio:null,
      crossfadeSourceNode:null,
      crossfadeGainNode:null,
      activeCrossfadeClipId:'',
      pendingCrossfadeSeek:false,
      pendingCrossfadePlay:false,
      trimNode:null,
      gainNode: null,
      panNode: null,
      analyserNode: null,
      liveCaptureTap: null,
      frequencyData: null,
      timeDomainData: null,
      spectrumPeakLevels: new Array(8).fill(0),
      spectrumDisplayLevels: new Array(8).fill(0),
      sourceNode: null,
      pendingPlay: false,
      pendingBoundarySeek:false,
      waveformData:null,
      waveformLoading:false,
      waveformQueued:false,
      waveformError:false,
      initialGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1))),
      initialPan: Math.max(-1, Math.min(1, Number(meta.pan || 0))),
      userGain: Math.max(0, Math.min(1.5, Number(meta.volume || 1)))
    };
  }).filter(stem => stem.audio);

  stems.forEach(stem => {
    if (
      Number(
        stem.takeOfStemId || 0
      ) > 0
    ) {
      [
        stem.leftRow,
        stem.mixer,
        stem.arrangeRow
      ].forEach(element => {
        element?.classList.add(
          'daw-take-lane'
        );

        if (element) {
          element.dataset.takeOfStem =
            String(
              stem.takeOfStemId
            );
        }
      });
    }

    if (stem.isEmptyRecordingTrack) {
      [
        stem.leftRow,
        stem.mixer,
        stem.arrangeRow
      ].forEach(element =>
        element?.classList.add(
          'daw-empty-recording-track'
        )
      );
    }
  });

  const stemById = id => stems.find(stem => stem.id === Number(id));

  function pluginTargetByKey(key) {
    const value = String(key || '');

    const stem = stems.find(
      item => item.pluginKey === value
    );

    if (stem) {
      return stem;
    }

    if (fixedPluginTargets[value]) {
      return fixedPluginTargets[value];
    }

    return customBuses.find(
      bus => bus.pluginKey === value
    ) || null;
  }

  function pluginTargetLabel(target) {
    if (!target) return 'TRACK';

    return String(
      target.label ||
      target.stem_name ||
      target.name ||
      target.pluginKey ||
      'TRACK'
    );
  }

  function defaultFixedTargetPlugins(key) {
    if (key === 'aux-a') {
      return [{
        type:'reverb',
        enabled:true,
        params:{
          decay:1.9,
          size:1.0,
          damping:9000,
          mix:1
        }
      }];
    }

    if (key === 'aux-b') {
      return [{
        type:'delay',
        enabled:true,
        params:{
          time:0.32,
          feedback:0.34,
          mix:1
        }
      }];
    }

    return [];
  }

  function setupFixedPluginTargetDom() {
    Object.values(fixedPluginTargets)
      .forEach(target => {
        target.pluginList =
          document.querySelector(
            `[data-universal-plugin-list="${target.key}"]`
          );

        target.addPluginButton =
          document.querySelector(
            `[data-add-universal-plugin="${target.key}"]`
          );
      });
  }

  setupFixedPluginTargetDom();

  function localViewState() {
    return {
      arrangeScrollLeft: Number(dawArrange?.scrollLeft || 0),
      arrangeScrollTop: Number(dawArrange?.scrollTop || 0),
      trackScrollTop: Number(trackList?.scrollTop || 0),
      mixerScrollLeft: Number(mixerScroll?.scrollLeft || 0),
      selectedStemId: Number(selectedStemId || 0),
      inspectorOpen:Boolean(
        trackInspector?.classList.contains(
          'open'
        )
      ),
      pluginRackOpen:Boolean(pluginRackOpen),
      pluginRackHeight:Number(pluginRackHeight || 348),
      timelineZoom:Number(timelineZoom || 1),
      editSnapMode:String(editSnapMode || 'grid'),
      automationOpen:stems
        .filter(stem => stem.automationOpen)
        .map(stem => stem.id),
      automationParameters:Object.fromEntries(
        stems.map(stem => [
          String(stem.id),
          stem.automationParameter?.value ||
            'volume'
        ])
      )
    };
  }

  function cloneMixState(
    state = collectMixState()
  ) {
    return JSON.parse(
      JSON.stringify(state)
    );
  }

  function setUndoBaseline(
    state = collectMixState()
  ) {
    const clean = cloneMixState(state);

    undoBaseline = clean;
    undoBaselineJson =
      JSON.stringify(clean);
    undoGroupOpen = false;

    window.clearTimeout(
      undoGroupTimer
    );
  }

  function noteUndoableChange() {
    if (
      !localPersistenceReady ||
      applyingUndo
    ) {
      return;
    }

    const current =
      cloneMixState();
    const currentJson =
      JSON.stringify(current);

    if (!undoBaseline) {
      undoBaseline = current;
      undoBaselineJson =
        currentJson;
      return;
    }

    if (
      currentJson ===
      undoBaselineJson
    ) {
      return;
    }

    if (!undoGroupOpen) {
      undoStack.push(
        cloneMixState(
          undoBaseline
        )
      );

      if (
        undoStack.length >
        undoLimit
      ) {
        undoStack.splice(
          0,
          undoStack.length -
            undoLimit
        );
      }

      undoGroupOpen = true;
    }

    window.clearTimeout(
      undoGroupTimer
    );

    undoGroupTimer =
      window.setTimeout(
        () => {
          undoBaseline =
            cloneMixState();
          undoBaselineJson =
            JSON.stringify(
              undoBaseline
            );
          undoGroupOpen = false;
        },
        420
      );
  }

  function undoLastChange() {
    if (!undoStack.length) {
      return false;
    }

    window.clearTimeout(
      undoGroupTimer
    );

    const previous =
      undoStack.pop();

    applyingUndo = true;

    try {
      applyMixState(
        cloneMixState(previous)
      );
    } finally {
      applyingUndo = false;
    }

    setUndoBaseline(
      previous
    );

    saveLocalStateNow();

    return true;
  }

  function saveLocalStateNow() {
    if (!localPersistenceReady) return;

    try {
      localStorage.setItem(
        localStateKey,
        JSON.stringify({
          schemaVersion: 1,
          savedAt: Date.now(),
          trackId,
          userId,
          mix: collectMixState(),
          view: localViewState()
        })
      );
    } catch (error) {
      console.warn(
        'Stem Studio local autosave unavailable.',
        error
      );
    }
  }

  function scheduleLocalSave(delay = 180) {
    if (!localPersistenceReady) return;

    noteUndoableChange();
    v90QueueManualLedger(
      undoBaseline
        ? cloneMixState(undoBaseline)
        : null
    );

    window.clearTimeout(localSaveTimer);
    localSaveTimer = window.setTimeout(
      saveLocalStateNow,
      delay
    );
  }

  function markSelectedStem(stemId) {
    selectedStemId = Number(stemId || 0);

    document.querySelectorAll(
      '.daw-track-row.selected,' +
      '.daw-stem-channel.selected,' +
      '.daw-arrange-row.selected'
    ).forEach(el => el.classList.remove('selected'));

    const stem = stemById(selectedStemId);

    stem?.leftRow?.classList.add('selected');
    stem?.mixer?.classList.add('selected');
    stem?.arrangeRow?.classList.add('selected');

    updateTrackInspector();
  }

  function inspectorSelectedStem() {
    return stemById(
      selectedStemId
    ) || stems[0] || null;
  }

  function populateInspectorSelect(
    target,
    source
  ) {
    if (!target || !source) {
      return;
    }

    const current =
      String(source.value || '');

    target.innerHTML =
      [...source.options]
        .map(option =>
          `<option value="${escapeHtml(option.value)}">${escapeHtml(option.textContent || option.value)}</option>`
        )
        .join('');

    if (
      [...target.options].some(
        option =>
          option.value === current
      )
    ) {
      target.value = current;
    }
  }

  function updateTrackInspector() {
    const stem =
      inspectorSelectedStem();

    if (!stem) {
      if (inspectorTrackName) {
        inspectorTrackName.textContent =
          'No track selected';
      }
      return;
    }

    if (inspectorTrackName) {
      inspectorTrackName.textContent =
        String(
          stem.name ||
          stem.label ||
          `Track ${stem.id}`
        );
    }

    if (inspectorTrackMeta) {
      const takeLabel =
        Number(
          stem.takeOfStemId || 0
        ) > 0
          ? 'TAKE · '
          : '';

      inspectorTrackMeta.textContent =
        `${takeLabel}${String(stem.role || 'Other')} · ${Math.round(Number(stem.sourceTempo || sourceTempo))} BPM`;
    }

    if (
      inspectorTrackNameInput &&
      document.activeElement !==
        inspectorTrackNameInput
    ) {
      inspectorTrackNameInput.value =
        String(
          stem.name ||
          stem.label ||
          ''
        );
    }

    if (
      inspectorTrackRole &&
      [...inspectorTrackRole.options]
        .some(
          option =>
            option.value ===
            String(
              stem.role ||
              'Other'
            )
        )
    ) {
      inspectorTrackRole.value =
        String(
          stem.role ||
          'Other'
        );
    }

    if (inspectorTrackState) {
      const states = [];

      if (
        recordingArmedStemId ===
        stem.id
      ) {
        states.push('ARMED');
      }

      if (stem.muted) {
        states.push('MUTED');
      }

      if (stem.solo) {
        states.push('SOLO');
      }

      inspectorTrackState.textContent =
        states.join(' · ') ||
        'ACTIVE';
    }

    populateInspectorSelect(
      inspectorTrackInput,
      stem.inputSelect
    );

    populateInspectorSelect(
      inspectorTrackBus,
      stem.groupSelect
    );

    if (inspectorVolume) {
      inspectorVolume.value =
        String(stem.userGain);
    }

    if (inspectorVolumeValue) {
      inspectorVolumeValue.textContent =
        dbText(stem.userGain);
    }

    if (inspectorPan) {
      inspectorPan.value =
        String(
          stem.pan?.value || 0
        );
    }

    if (inspectorPanValue) {
      inspectorPanValue.textContent =
        panText(
          Number(
            stem.pan?.value || 0
          )
        );
    }

    if (inspectorTrim) {
      inspectorTrim.value =
        String(stem.trimDb || 0);
    }

    if (inspectorTrimValue) {
      const trim =
        Number(stem.trimDb || 0);

      inspectorTrimValue.textContent =
        `${trim >= 0 ? '+' : ''}${trim.toFixed(1)} dB`;
    }

    if (inspectorSendA) {
      inspectorSendA.value =
        String(stem.sends.a || 0);
    }

    if (inspectorSendAValue) {
      inspectorSendAValue.textContent =
        `${Math.round(
          Number(stem.sends.a || 0) *
          100
        )}%`;
    }

    if (inspectorSendB) {
      inspectorSendB.value =
        String(stem.sends.b || 0);
    }

    if (inspectorSendBValue) {
      inspectorSendBValue.textContent =
        `${Math.round(
          Number(stem.sends.b || 0) *
          100
        )}%`;
    }

    inspectorArm?.classList.toggle(
      'active',
      recordingArmedStemId ===
        stem.id
    );
    inspectorMute?.classList.toggle(
      'active',
      stem.muted
    );
    inspectorSolo?.classList.toggle(
      'active',
      stem.solo
    );
    inspectorAutomation?.classList.toggle(
      'active',
      stem.automationOpen
    );

    const plugins =
      Array.isArray(
        stem.plugins
      )
        ? stem.plugins
        : [];

    if (inspectorPluginCount) {
      inspectorPluginCount.textContent =
        `${plugins.length} insert${
          plugins.length === 1
            ? ''
            : 's'
        }`;
    }

    if (inspectorPluginList) {
      inspectorPluginList.innerHTML =
        plugins.length
          ? plugins
              .map(
                (plugin,index) => `
                  <button
                    type="button"
                    data-inspector-plugin="${index}"
                    class="${plugin.enabled === false ? 'bypassed' : ''}"
                  >
                    <strong>${escapeHtml(pluginLabel(plugin))}</strong>
                    <span>${plugin.enabled === false ? 'BYPASSED' : 'ACTIVE'}</span>
                  </button>
                `
              )
              .join('')
          : '<span>No plugins inserted.</span>';

      inspectorPluginList
        .querySelectorAll(
          '[data-inspector-plugin]'
        )
        .forEach(button => {
          button.addEventListener(
            'click',
            () => {
              const index =
                Number(
                  button.dataset
                    .inspectorPlugin ||
                  0
                );

              pluginTargetKey =
                stem.pluginKey;
              pluginEditIndex =
                index;

              const plugin =
                stem.plugins[index];

              if (!plugin) {
                return;
              }

              openPluginEditor(
                stem.pluginKey,
                index
              );
            }
          );
        });
    }

    const selected =
      selectedArrangementData();

    const clip = (
      selected?.kind === 'stem' &&
      selected.stem?.id === stem.id
    )
      ? selected.clip
      : null;

    const clipControls = [
      inspectorClipGain,
      inspectorFadeIn,
      inspectorFadeOut,
      inspectorClipMute,
      inspectorAutoCrossfade
    ];

    clipControls.forEach(control => {
      if (control) {
        control.disabled = !clip;
      }
    });

    if (inspectorClipName) {
      inspectorClipName.textContent =
        clip
          ? `${formatTime(clip.timelineStart)} · ${formatTime(clip.timelineLength)}`
          : 'Select a clip';
    }

    if (clip) {
      const maxFade = Math.max(
        0,
        Number(clip.timelineLength || 0)
      );

      if (inspectorClipGain) {
        inspectorClipGain.value =
          String(
            Number(clip.gainDb || 0)
          );
      }

      if (inspectorClipGainValue) {
        const value =
          Number(clip.gainDb || 0);

        inspectorClipGainValue.textContent =
          `${value >= 0 ? '+' : ''}${value.toFixed(1)} dB`;
      }

      if (inspectorFadeIn) {
        inspectorFadeIn.max =
          String(maxFade);
        inspectorFadeIn.value =
          String(
            Math.min(
              maxFade,
              Number(clip.fadeIn || 0)
            )
          );
      }

      if (inspectorFadeInValue) {
        inspectorFadeInValue.textContent =
          `${Number(clip.fadeIn || 0).toFixed(2)}s`;
      }

      if (inspectorFadeOut) {
        inspectorFadeOut.max =
          String(maxFade);
        inspectorFadeOut.value =
          String(
            Math.min(
              maxFade,
              Number(clip.fadeOut || 0)
            )
          );
      }

      if (inspectorFadeOutValue) {
        inspectorFadeOutValue.textContent =
          `${Number(clip.fadeOut || 0).toFixed(2)}s`;
      }

      inspectorClipMute?.classList.toggle(
        'active',
        Boolean(clip.muted)
      );
    }
  }

  function setTrackInspectorOpen(
    open,
    stem = null
  ) {
    if (!trackInspector) {
      return;
    }

    if (stem) {
      markSelectedStem(
        stem.id
      );
    } else if (
      !selectedStemId &&
      stems[0]
    ) {
      markSelectedStem(
        stems[0].id
      );
    }

    const active =
      Boolean(open);

    trackInspector.classList.toggle(
      'open',
      active
    );

    trackInspector.setAttribute(
      'aria-hidden',
      active ? 'false' : 'true'
    );

    if (trackInspectorBackdrop) {
      trackInspectorBackdrop.hidden =
        !active;
    }

    openTrackInspector?.setAttribute(
      'aria-expanded',
      active ? 'true' : 'false'
    );

    if (active) {
      updateTrackInspector();
    }
  }

  function restoreLocalState() {
    let payload = null;

    try {
      const raw = localStorage.getItem(localStateKey);

      if (raw) {
        payload = JSON.parse(raw);
      }
    } catch (error) {
      console.warn(
        'Stem Studio local restore unavailable.',
        error
      );
    }

    if (
      payload &&
      typeof payload === 'object' &&
      Number(payload.trackId || 0) === trackId &&
      payload.mix &&
      typeof payload.mix === 'object'
    ) {
      applyMixState(payload.mix);
    }

    const view = (
      payload &&
      typeof payload.view === 'object'
    )
      ? payload.view
      : {};

    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        if (dawArrange) {
          dawArrange.scrollLeft = Math.max(
            0,
            Number(view.arrangeScrollLeft || 0)
          );
          dawArrange.scrollTop = Math.max(
            0,
            Number(
              view.arrangeScrollTop ??
              view.trackScrollTop ??
              0
            )
          );
        }

        if (trackList) {
          trackList.scrollTop = Math.max(
            0,
            Number(
              view.trackScrollTop ??
              view.arrangeScrollTop ??
              0
            )
          );
        }

        if (mixerScroll) {
          mixerScroll.scrollLeft = Math.max(
            0,
            Number(view.mixerScrollLeft || 0)
          );
        }

        const savedStemId = Number(
          view.selectedStemId || 0
        );

        if (savedStemId && stemById(savedStemId)) {
          markSelectedStem(savedStemId);
        }

        setTrackInspectorOpen(
          Boolean(
            view.inspectorOpen
          )
        );

        setPluginRack(
          Boolean(view.pluginRackOpen),
          Number(view.pluginRackHeight || 348),
          false
        );

        setTimelineZoom(
          Number(view.timelineZoom || 1),
          false
        );

        setEditSnapMode(
          view.editSnapMode === 'free'
            ? 'free'
            : 'grid',
          false
        );

        const openAutomation = Array.isArray(
          view.automationOpen
        )
          ? view.automationOpen.map(Number)
          : [];

        stems.forEach(stem => {
          const savedParameter =
            view.automationParameters?.[
              String(stem.id)
            ];

          if (
            savedParameter &&
            ['volume','pan','auxA','auxB']
              .includes(savedParameter) &&
            stem.automationParameter
          ) {
            stem.automationParameter.value =
              savedParameter;
          }

          setAutomationOpen(
            stem,
            openAutomation.includes(stem.id),
            false
          );
        });

        localPersistenceReady = true;
        setUndoBaseline();
      });
    });
  }

  function transportRate() {
    return Math.max(
      0.25,
      Math.min(
        4,
        sessionTempo / sourceTempo
      )
    );
  }

  function globalPosition() {
    if (!playing) return position;

    const next =
      position +
      (
        (
          performance.now() -
          startedAt
        ) /
        1000
      ) *
      transportRate();

    return recordingActive
      ? Math.max(0,next)
      : Math.min(
          duration,
          next
        );
  }

  function setMediaTempo(audio,rate) {
    if (!audio) return;

    const clean = Math.max(
      0.25,
      Math.min(4,Number(rate || 1))
    );

    audio.playbackRate = clean;
    audio.defaultPlaybackRate = clean;

    if ('preservesPitch' in audio) {
      audio.preservesPitch = true;
    }

    if ('webkitPreservesPitch' in audio) {
      audio.webkitPreservesPitch = true;
    }
  }

  function applySessionTempoToMedia() {
    stems.forEach(stem => {
      const rate =
        sessionTempo /
        Math.max(
          40,
          Number(
            stem.sourceTempo ||
            sourceTempo
          )
        );

      setMediaTempo(
        stem.audio,
        rate
      );

      setMediaTempo(
        stem.crossfadeAudio,
        rate
      );
    });

    libraryClips.forEach(clip => {
      setMediaTempo(
        clip.audio,
        sessionTempo /
          Math.max(
            40,
            Number(
              clip.sourceTempo ||
              sourceTempo
            )
          )
      );
    });
  }

  function setSessionTempo(
    value,
    persist = true
  ) {
    const next = Math.round(
      Math.max(
        40,
        Math.min(300,Number(value || sourceTempo))
      ) * 10
    ) / 10;

    const wasPlaying = playing;
    const current = globalPosition();

    sessionTempo = next;

    if (sessionTempoInput) {
      sessionTempoInput.value =
        String(sessionTempo);
    }

    if (wasPlaying) {
      position = current;
      startedAt = performance.now();
    }

    applySessionTempoToMedia();

    if (wasPlaying) {
      syncLibraryClipPlayback(
        position,
        false
      );
    }

    if (persist) {
      scheduleLocalSave(0);
    }
  }

  function timeSignatureQuarterBeats(
    signature = sessionTimeSignature
  ) {
    const match = String(signature)
      .match(/^(\d+)\s*\/\s*(\d+)$/);

    if (!match) {
      return 4;
    }

    const numerator = Math.max(
      1,
      Number(match[1] || 4)
    );
    const denominator = Math.max(
      1,
      Number(match[2] || 4)
    );

    return numerator * (4 / denominator);
  }

  function barsToTimelineSeconds(bars = 4) {
    const beats =
      Math.max(0,bars) *
      timeSignatureQuarterBeats();

    return beats *
      60 /
      sourceTempo;
  }

  function makeImpulse(ctx, seconds = 1.1, decay = 2.6) {
    const length = Math.max(1, Math.floor(ctx.sampleRate * seconds));
    const buffer = ctx.createBuffer(2, length, ctx.sampleRate);

    for (let channel = 0; channel < 2; channel++) {
      const data = buffer.getChannelData(channel);

      for (let i = 0; i < length; i++) {
        data[i] = (Math.random() * 2 - 1)
          * Math.pow(1 - i / length, decay);
      }
    }

    return buffer;
  }

  function defaultPlugin(type) {
    if (type === 'eq5') {
      return {
        type:'eq5',
        enabled:true,
        params:{
          f1:80,f2:250,f3:1000,f4:4000,f5:12000,
          b1:0,b2:0,b3:0,b4:0,b5:0
        }
      };
    }

    if (type === 'delay') {
      return {
        type:'delay',
        enabled:true,
        params:{
          time:0.28,
          feedback:0.32,
          mix:0.20
        }
      };
    }

    if (type === 'compressor') {
      return {
        type:'compressor',
        enabled:true,
        params:{
          threshold:-18,
          ratio:4,
          knee:12,
          attack:0.012,
          release:0.24,
          makeup:0
        }
      };
    }

    if (type === 'limiter') {
      return {
        type:'limiter',
        enabled:true,
        params:{threshold:-6,ceiling:-1,release:0.10,lookahead:0.003}
      };
    }

    return {
      type:'reverb',
      enabled:true,
      params:{
        decay:1.8,
        size:1.0,
        damping:9000,
        mix:0.18
      }
    };
  }

  function normalizeTrackPlugins(plugins) {
    if (!Array.isArray(plugins)) return [];

    const freqRanges = [
      [40,180],
      [120,700],
      [500,2500],
      [1800,8000],
      [6000,18000]
    ];

    return plugins.slice(0,6)
      .map(plugin => {
        if (
          !plugin ||
          !['eq5','delay','compressor','reverb','limiter']
            .includes(plugin.type)
        ) {
          return null;
        }

        const base = defaultPlugin(plugin.type);
        const params = {
          ...base.params,
          ...(plugin.params || {})
        };

        if (plugin.type === 'eq5') {
          ['b1','b2','b3','b4','b5'].forEach(key => {
            params[key] = Math.max(
              -18,
              Math.min(18,Number(params[key] || 0))
            );
          });

          ['f1','f2','f3','f4','f5'].forEach((key,index) => {
            const [min,max] = freqRanges[index];
            params[key] = Math.max(
              min,
              Math.min(
                max,
                Number(params[key] || base.params[key])
              )
            );
          });
        } else if (plugin.type === 'delay') {
          params.time = Math.max(
            0.02,
            Math.min(1.5,Number(params.time || 0.28))
          );
          params.feedback = Math.max(
            0,
            Math.min(0.92,Number(params.feedback || 0.32))
          );
          params.mix = Math.max(
            0,
            Math.min(1,Number(params.mix || 0.20))
          );
        } else if (plugin.type === 'compressor') {
          params.threshold = Math.max(
            -60,
            Math.min(0,Number(params.threshold ?? -18))
          );
          params.ratio = Math.max(
            1,
            Math.min(20,Number(params.ratio || 4))
          );
          params.knee = Math.max(
            0,
            Math.min(40,Number(params.knee ?? 12))
          );
          params.attack = Math.max(
            0.001,
            Math.min(1,Number(params.attack || 0.012))
          );
          params.release = Math.max(
            0.01,
            Math.min(3,Number(params.release || 0.24))
          );
          params.makeup = Math.max(
            -6,
            Math.min(18,Number(params.makeup || 0))
          );
        } else if (plugin.type === 'limiter') {
          params.threshold = Math.max(-36,Math.min(0,Number(params.threshold ?? -6)));
          params.ceiling = Math.max(-12,Math.min(0,Number(params.ceiling ?? -1)));
          params.release = Math.max(0.02,Math.min(2,Number(params.release || 0.10)));
          params.lookahead = Math.max(0,Math.min(0.05,Number(params.lookahead || 0.003)));
        } else {
          params.decay = Math.max(
            0.2,
            Math.min(8,Number(params.decay || 1.8))
          );
          params.size = Math.max(
            0.25,
            Math.min(2.5,Number(params.size || 1))
          );
          params.damping = Math.max(
            800,
            Math.min(20000,Number(params.damping || 9000))
          );
          params.mix = Math.max(
            0,
            Math.min(1,Number(params.mix || 0.18))
          );
        }

        return {
          type:plugin.type,
          enabled:plugin.enabled !== false,
          params,
          source_name:String(plugin.source_name || '').slice(0,160),
          source_format:String(plugin.source_format || '').slice(0,20),
          source_preset:String(plugin.source_preset || '').slice(0,160),
          source_fxid:String(plugin.source_fxid || '').slice(0,80),
          mapping:['exact','translated','estimated'].includes(plugin.mapping)
            ? plugin.mapping
            : '',
          confidence:Math.max(0,Math.min(1,Number(plugin.confidence || 0)))
        };
      })
      .filter(Boolean);
  }

  function pluginLabel(plugin) {
    if (plugin.type === 'eq5') return '5-Band EQ';
    if (plugin.type === 'delay') return 'Delay';
    if (plugin.type === 'compressor') return 'ReaComp Compressor';
    if (plugin.type === 'limiter') return 'Master Limiter';
    return 'Reverb';
  }

  function pluginShortLabel(plugin) {
    if (plugin.type === 'eq5') return 'EQ5';
    if (plugin.type === 'delay') return 'DLY';
    if (plugin.type === 'compressor') return 'CMP';
    if (plugin.type === 'limiter') return 'LIM';
    return 'RVB';
  }

  function renderPluginTargetList(target) {
    if (!target?.pluginList) return;

    target.pluginList.innerHTML =
      target.plugins.map((plugin,index) => `
        <button
          type="button"
          class="daw-track-plugin-chip${plugin.enabled ? '' : ' bypassed'}"
          data-plugin-target="${target.pluginKey || target.key}"
          data-plugin-index="${index}"
          draggable="true"
          title="Drag to reorder · Alt-click to delete · ${plugin.enabled ? 'Edit' : 'Bypassed'} ${pluginLabel(plugin)}"
        >
          <i class="daw-plugin-order-handle">⋮</i>
          <span>${pluginShortLabel(plugin)}</span>
          <small>${plugin.enabled ? 'ON' : 'OFF'}</small>
        </button>
      `).join('');

    let draggedIndex = -1;
    let didDrag = false;

    target.pluginList
      .querySelectorAll('[data-plugin-target]')
      .forEach(button => {
        button.addEventListener(
          'dragstart',
          event => {
            draggedIndex = Number(
              button.dataset.pluginIndex || -1
            );
            didDrag = true;
            button.classList.add(
              'dragging-plugin'
            );

            event.dataTransfer.effectAllowed =
              'move';
            event.dataTransfer.setData(
              'text/plain',
              String(draggedIndex)
            );
          }
        );

        button.addEventListener(
          'dragover',
          event => {
            if (draggedIndex < 0) return;

            event.preventDefault();
            button.classList.add(
              'plugin-drop-target'
            );
          }
        );

        button.addEventListener(
          'dragleave',
          () => {
            button.classList.remove(
              'plugin-drop-target'
            );
          }
        );

        button.addEventListener(
          'drop',
          event => {
            event.preventDefault();
            button.classList.remove(
              'plugin-drop-target'
            );

            const targetIndex = Number(
              button.dataset.pluginIndex || -1
            );

            if (
              draggedIndex < 0 ||
              targetIndex < 0 ||
              draggedIndex === targetIndex
            ) {
              return;
            }

            const [plugin] =
              target.plugins.splice(
                draggedIndex,
                1
              );

            target.plugins.splice(
              targetIndex,
              0,
              plugin
            );

            renderPluginTargetList(target);

            if (context) {
              rebuildPluginTargetGraph(target);
            }

            scheduleLocalSave(0);
          }
        );

        button.addEventListener(
          'dragend',
          () => {
            draggedIndex = -1;
            button.classList.remove(
              'dragging-plugin'
            );

            requestAnimationFrame(() => {
              didDrag = false;
            });
          }
        );

        button.addEventListener(
          'click',
          event => {
            event.stopPropagation();

            if (didDrag) {
              return;
            }

            const index = Number(
              button.dataset.pluginIndex || 0
            );

            if (event.altKey) {
              event.preventDefault();

              if (
                index < 0 ||
                index >= target.plugins.length
              ) {
                return;
              }

              target.plugins.splice(
                index,
                1
              );

              renderPluginTargetList(
                target
              );

              if (context) {
                rebuildPluginTargetGraph(
                  target
                );
              }

              scheduleLocalSave(0);
              return;
            }

            openPluginEditor(
              button.dataset.pluginTarget,
              index
            );
          }
        );
      });

    if (target.addPluginButton) {
      target.addPluginButton.hidden =
        target.plugins.length >= 6;
    }

    if (
      target.kind === 'stem' &&
      Number(target.id || 0) ===
        Number(selectedStemId || 0)
    ) {
      updateTrackInspector();
    }
  }

  function renderTrackPluginList(stem) {
    renderPluginTargetList(stem);
  }


  function setPluginRack(open, height = null, persist = true) {
    pluginRackOpen = Boolean(open);

    if (height !== null && Number.isFinite(Number(height))) {
      pluginRackHeight = Math.max(
        348,
        Math.min(820, Number(height))
      );
    } else {
      pluginRackHeight = pluginRackOpen ? 520 : 348;
    }

    if (studio) {
      studio.classList.toggle(
        'plugin-rack-open',
        pluginRackOpen
      );

      studio.style.setProperty(
        '--mixer-height',
        `${pluginRackOpen ? pluginRackHeight : 348}px`
      );
    }

    pluginRackHandle?.setAttribute(
      'aria-expanded',
      pluginRackOpen ? 'true' : 'false'
    );

    if (pluginRackHandle) {
      const small = pluginRackHandle.querySelector('small');
      if (small) {
        small.textContent = pluginRackOpen
          ? 'drag down'
          : 'drag up';
      }
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function disconnectPluginTargetNodes(target) {
    (target?.pluginNodes || []).forEach(node => {
      try {
        node.disconnect();
      } catch (error) {}
    });

    if (target) {
      target.pluginNodes = [];

      (target.plugins || []).forEach(plugin => {
        if (plugin?._runtime?.rebuildTimer) {
          window.clearTimeout(
            plugin._runtime.rebuildTimer
          );
        }

        delete plugin._runtime;
      });
    }
  }

  function createPluginGraph(plugin) {
    if (plugin.type === 'eq5') {
      return createEq5Graph(plugin);
    }

    if (plugin.type === 'delay') {
      return createDelayGraph(plugin);
    }

    if (plugin.type === 'compressor') {
      return createCompressorGraph(plugin);
    }

    if (plugin.type === 'limiter') {
      return createLimiterGraph(plugin);
    }

    return createReverbGraph(plugin);
  }

  function connectPluginChain(
    target,
    source,
    destination
  ) {
    let current = source;

    (target.plugins || []).forEach(plugin => {
      if (!plugin.enabled) return;

      const graph = createPluginGraph(plugin);

      current.connect(graph.input);
      current = graph.output;

      target.pluginNodes.push(
        ...graph.nodes
      );
    });

    current.connect(destination);
  }

  function createEq5Graph(plugin) {
    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];

    const filters = frequencyKeys.map((frequencyKey, index) => {
      const node = context.createBiquadFilter();

      node.type = index === 0
        ? 'lowshelf'
        : index === frequencyKeys.length - 1
          ? 'highshelf'
          : 'peaking';

      node.frequency.value = Number(plugin.params[frequencyKey]);
      node.Q.value = index === 0 || index === frequencyKeys.length - 1
        ? 0.7
        : 1.0;
      node.gain.value = Number(plugin.params[gainKeys[index]] || 0);

      return node;
    });

    for (let i = 0; i < filters.length - 1; i++) {
      filters[i].connect(filters[i + 1]);
    }

    plugin._runtime = {
      type: 'eq5',
      filters
    };

    return {
      input: filters[0],
      output: filters[filters.length - 1],
      nodes: filters
    };
  }

  function createDelayGraph(plugin) {
    const input = context.createGain();
    const output = context.createGain();
    const dry = context.createGain();
    const wet = context.createGain();
    const delay = context.createDelay(2.0);
    const feedback = context.createGain();

    delay.delayTime.value = Number(plugin.params.time || 0.28);
    feedback.gain.value = Number(plugin.params.feedback || 0.32);

    const mix = Math.max(
      0,
      Math.min(1, Number(plugin.params.mix || 0.20))
    );

    dry.gain.value = 1 - mix;
    wet.gain.value = mix;

    input.connect(dry);
    dry.connect(output);

    input.connect(delay);
    delay.connect(wet);
    wet.connect(output);

    delay.connect(feedback);
    feedback.connect(delay);

    plugin._runtime = {
      type: 'delay',
      delay,
      feedback,
      dry,
      wet
    };

    return {
      input,
      output,
      nodes: [input, output, dry, wet, delay, feedback]
    };
  }

  function createCompressorGraph(plugin) {
    const input = context.createGain();
    const compressorNode = context.createDynamicsCompressor();
    const makeup = context.createGain();
    const output = context.createGain();

    compressorNode.threshold.value = Number(plugin.params.threshold);
    compressorNode.ratio.value = Number(plugin.params.ratio);
    compressorNode.knee.value = Number(plugin.params.knee);
    compressorNode.attack.value = Number(plugin.params.attack);
    compressorNode.release.value = Number(plugin.params.release);
    makeup.gain.value = Math.pow(
      10,
      Number(plugin.params.makeup || 0) / 20
    );

    input.connect(compressorNode);
    compressorNode.connect(makeup);
    makeup.connect(output);

    plugin._runtime = {
      type:'compressor',
      compressor:compressorNode,
      makeup
    };

    return {
      input,
      output,
      nodes:[input,compressorNode,makeup,output]
    };
  }


  function createLimiterGraph(plugin) {
    const input = context.createGain();
    const limiterNode = context.createDynamicsCompressor();
    const ceiling = context.createGain();
    const clipper = context.createWaveShaper();
    const output = context.createGain();

    limiterNode.threshold.value = Number(plugin.params.threshold ?? -6);
    limiterNode.knee.value = 0;
    limiterNode.ratio.value = 20;
    limiterNode.attack.value = Math.max(0.001,Number(plugin.params.lookahead || 0.003));
    limiterNode.release.value = Number(plugin.params.release || 0.10);
    ceiling.gain.value = Math.pow(10,Number(plugin.params.ceiling ?? -1) / 20);

    const curve = new Float32Array(4096);
    for (let i = 0; i < curve.length; i++) {
      const x = (i / (curve.length - 1)) * 2 - 1;
      curve[i] = Math.tanh(x * 1.35) / Math.tanh(1.35);
    }
    clipper.curve = curve;
    clipper.oversample = '4x';

    input.connect(limiterNode);
    limiterNode.connect(clipper);
    clipper.connect(ceiling);
    ceiling.connect(output);

    plugin._runtime = {type:'limiter',limiter:limiterNode,ceiling,clipper};
    return {input,output,nodes:[input,limiterNode,clipper,ceiling,output]};
  }

  function createReverbGraph(plugin) {
    const input = context.createGain();
    const output = context.createGain();
    const dry = context.createGain();
    const wet = context.createGain();
    const convolver = context.createConvolver();
    const damping = context.createBiquadFilter();

    const mix = Math.max(
      0,
      Math.min(1,Number(plugin.params.mix || 0.18))
    );

    dry.gain.value = 1 - mix;
    wet.gain.value = mix;

    damping.type = 'lowpass';
    damping.frequency.value = Number(
      plugin.params.damping || 9000
    );

    convolver.buffer = makeImpulse(
      context,
      Number(plugin.params.decay || 1.8),
      Math.max(
        1.2,
        3.4 / Number(plugin.params.size || 1)
      )
    );

    input.connect(dry);
    dry.connect(output);

    input.connect(convolver);
    convolver.connect(damping);
    damping.connect(wet);
    wet.connect(output);

    plugin._runtime = {
      type:'reverb',
      convolver,
      damping,
      dry,
      wet,
      rebuildTimer:0
    };

    return {
      input,
      output,
      nodes:[input,output,dry,wet,convolver,damping]
    };
  }

  function updateTrackPluginAudio(plugin) {
    if (!context || !plugin?._runtime) {
      return;
    }

    const now = context.currentTime;

    if (
      plugin.type === 'eq5' &&
      plugin._runtime.type === 'eq5'
    ) {
      const frequencyKeys = ['f1','f2','f3','f4','f5'];
      const gainKeys = ['b1','b2','b3','b4','b5'];

      plugin._runtime.filters.forEach((filter,index) => {
        filter.frequency.setTargetAtTime(
          Number(plugin.params[frequencyKeys[index]]),
          now,
          0.012
        );
        filter.gain.setTargetAtTime(
          Number(plugin.params[gainKeys[index]]),
          now,
          0.012
        );
      });
      return;
    }

    if (
      plugin.type === 'delay' &&
      plugin._runtime.type === 'delay'
    ) {
      const mix = Math.max(
        0,
        Math.min(1,Number(plugin.params.mix || 0))
      );

      plugin._runtime.delay.delayTime.setTargetAtTime(
        Number(plugin.params.time || 0.28),
        now,
        0.018
      );
      plugin._runtime.feedback.gain.setTargetAtTime(
        Number(plugin.params.feedback || 0.32),
        now,
        0.018
      );
      plugin._runtime.dry.gain.setTargetAtTime(
        1 - mix,
        now,
        0.018
      );
      plugin._runtime.wet.gain.setTargetAtTime(
        mix,
        now,
        0.018
      );
      return;
    }

    if (
      plugin.type === 'compressor' &&
      plugin._runtime.type === 'compressor'
    ) {
      const node = plugin._runtime.compressor;

      node.threshold.setTargetAtTime(
        Number(plugin.params.threshold),
        now,
        0.012
      );
      node.ratio.setTargetAtTime(
        Number(plugin.params.ratio),
        now,
        0.012
      );
      node.knee.setTargetAtTime(
        Number(plugin.params.knee),
        now,
        0.012
      );
      node.attack.setTargetAtTime(
        Number(plugin.params.attack),
        now,
        0.012
      );
      node.release.setTargetAtTime(
        Number(plugin.params.release),
        now,
        0.018
      );
      plugin._runtime.makeup.gain.setTargetAtTime(
        Math.pow(
          10,
          Number(plugin.params.makeup || 0) / 20
        ),
        now,
        0.018
      );
      return;
    }

    if (
      plugin.type === 'limiter' &&
      plugin._runtime.type === 'limiter'
    ) {
      plugin._runtime.limiter.threshold.setTargetAtTime(Number(plugin.params.threshold ?? -6),now,0.01);
      plugin._runtime.limiter.release.setTargetAtTime(Number(plugin.params.release || 0.10),now,0.01);
      plugin._runtime.limiter.attack.setTargetAtTime(Math.max(0.001,Number(plugin.params.lookahead || 0.003)),now,0.01);
      plugin._runtime.ceiling.gain.setTargetAtTime(Math.pow(10,Number(plugin.params.ceiling ?? -1)/20),now,0.01);
      return;
    }

    if (
      plugin.type === 'reverb' &&
      plugin._runtime.type === 'reverb'
    ) {
      const mix = Math.max(
        0,
        Math.min(1,Number(plugin.params.mix || 0))
      );

      plugin._runtime.dry.gain.setTargetAtTime(
        1 - mix,
        now,
        0.02
      );
      plugin._runtime.wet.gain.setTargetAtTime(
        mix,
        now,
        0.02
      );
      plugin._runtime.damping.frequency.setTargetAtTime(
        Number(plugin.params.damping || 9000),
        now,
        0.02
      );

      window.clearTimeout(
        plugin._runtime.rebuildTimer || 0
      );

      plugin._runtime.rebuildTimer = window.setTimeout(
        () => {
          if (!context || !plugin?._runtime?.convolver) {
            return;
          }

          plugin._runtime.convolver.buffer = makeImpulse(
            context,
            Number(plugin.params.decay || 1.8),
            Math.max(
              1.2,
              3.4 / Number(plugin.params.size || 1)
            )
          );
        },
        90
      );
    }
  }

  function rebuildTrackPluginGraph(stem) {
    if (
      !context ||
      !stem.analyserNode ||
      !stem.gainNode
    ) {
      return;
    }

    const sourceOutput =
      stem.panNode ||
      stem.gainNode;

    try {
      sourceOutput.disconnect();
    } catch (error) {}

    disconnectPluginTargetNodes(stem);

    // Stem inserts are post-fader / post-pan and pre-spectrum.
    let current = sourceOutput;

    (stem.plugins || []).forEach(plugin => {
      if (!plugin.enabled) return;

      const graph = createPluginGraph(plugin);
      current.connect(graph.input);
      current = graph.output;
      stem.pluginNodes.push(...graph.nodes);
    });

    // A stable post-fader/post-plugin tap keeps a live recording connected
    // even when plugins or the destination bus are changed during the take.
    current.connect(
      stem.liveCaptureTap || stem.analyserNode
    );
  }

  function rebuildAuxPluginGraph(target) {
    if (!context || !target) return;

    const isA = target.kind === 'aux-a';
    const source = isA
      ? auxAInput
      : auxBInput;
    const destination = isA
      ? auxAReturnGain
      : auxBReturnGain;

    if (!source || !destination) {
      return;
    }

    try {
      source.disconnect();
    } catch (error) {}

    disconnectPluginTargetNodes(target);

    connectPluginChain(
      target,
      source,
      destination
    );
  }

  function rebuildGroupPluginGraph(target) {
    if (
      !context ||
      !target?.group ||
      !groupState[target.group]
    ) {
      return;
    }

    const state =
      groupState[target.group];

    if (
      !state.input ||
      !state.analyser ||
      !state.gain
    ) {
      return;
    }

    try {
      state.input.disconnect();
    } catch (error) {}

    disconnectPluginTargetNodes(target);

    connectPluginChain(
      target,
      state.input,
      state.analyser
    );

    try {
      state.analyser.disconnect();
    } catch (error) {}

    state.analyser.connect(state.gain);

    try {
      state.gain.disconnect();
    } catch (error) {}

    state.gain.connect(busInput);
  }

  function rebuildCustomBusPluginGraph(bus) {
    if (
      !context ||
      !bus?.input ||
      !bus?.analyser ||
      !bus?.gain
    ) {
      return;
    }

    try {
      bus.input.disconnect();
    } catch (error) {}

    disconnectPluginTargetNodes(bus);

    connectPluginChain(
      bus,
      bus.input,
      bus.analyser
    );

    try {
      bus.analyser.disconnect();
    } catch (error) {}

    bus.analyser.connect(bus.gain);

    try {
      bus.gain.disconnect();
    } catch (error) {}

    bus.gain.connect(busInput);
  }

  function rebuildPluginTargetGraph(target) {
    if (!context || !target) return;

    if (target.kind === 'stem') {
      rebuildTrackPluginGraph(target);
      return;
    }

    if (target.kind === 'master') {
      rebuildMasterGraph();
      return;
    }

    if (
      target.kind === 'aux-a' ||
      target.kind === 'aux-b'
    ) {
      rebuildAuxPluginGraph(target);
      return;
    }

    if (target.kind === 'group') {
      rebuildGroupPluginGraph(target);
      return;
    }

    if (target.kind === 'custom-bus') {
      rebuildCustomBusPluginGraph(target);
    }
  }

  function rebuildAllTrackPluginGraphs() {
    if (!context) return;

    stems.forEach(stem => {
      rebuildTrackPluginGraph(stem);
    });

    Object.values(fixedPluginTargets)
      .forEach(target => {
        if (target.kind !== 'master') {
          rebuildPluginTargetGraph(target);
        }
      });

    customBuses.forEach(bus => {
      rebuildCustomBusPluginGraph(bus);
    });

    rebuildMasterGraph();
  }


  function openPluginDirectory(targetKey) {
    const target =
      pluginTargetByKey(targetKey);

    if (!target) return;

    pluginTargetKey =
      target.pluginKey ||
      target.key;
    pluginEditIndex = -1;

    if (pluginDirectoryTrack) {
      pluginDirectoryTrack.innerHTML = `
        <span>INSERT ON</span>
        <strong>${escapeHtml(pluginTargetLabel(target))}</strong>
      `;
    }

    if (pluginEditor) {
      pluginEditor.hidden = true;
    }

    document.getElementById(
      'pluginDirectoryGrid'
    )?.removeAttribute('hidden');

    openModal(pluginDirectoryDialog);
  }


  function eqFrequencyToX(frequency) {
    const min = 20;
    const max = 20000;
    const width = 680;
    const left = 42;

    return left + (
      Math.log10(frequency / min) /
      Math.log10(max / min)
    ) * width;
  }

  function eqXToFrequency(x) {
    const min = 20;
    const max = 20000;
    const width = 680;
    const left = 42;
    const ratio = Math.max(
      0,
      Math.min(1, (x - left) / width)
    );

    return min * Math.pow(max / min, ratio);
  }

  function eqGainToY(gain) {
    const top = 20;
    const height = 240;
    return top + ((18 - gain) / 36) * height;
  }

  function eqYToGain(y) {
    const top = 20;
    const height = 240;
    const ratio = Math.max(
      0,
      Math.min(1, (y - top) / height)
    );
    return 18 - ratio * 36;
  }

  function formatFrequency(value) {
    const frequency = Number(value || 0);

    if (frequency >= 1000) {
      return `${(frequency / 1000).toFixed(
        frequency >= 10000 ? 1 : 2
      ).replace(/\.0+$/, '')} kHz`;
    }

    return `${Math.round(frequency)} Hz`;
  }

  function svgPoint(event, svg) {
    const point = svg.createSVGPoint();
    point.x = event.clientX;
    point.y = event.clientY;

    const matrix = svg.getScreenCTM();

    if (!matrix) {
      return {x:0,y:0};
    }

    const transformed = point.matrixTransform(
      matrix.inverse()
    );

    return {
      x: transformed.x,
      y: transformed.y
    };
  }

  function eqCurvePath(plugin) {
    const points = [
      [20, 0],
      [plugin.params.f1, plugin.params.b1],
      [plugin.params.f2, plugin.params.b2],
      [plugin.params.f3, plugin.params.b3],
      [plugin.params.f4, plugin.params.b4],
      [plugin.params.f5, plugin.params.b5],
      [20000, 0]
    ].map(([frequency, gain]) => [
      eqFrequencyToX(frequency),
      eqGainToY(gain)
    ]);

    let path = `M ${points[0][0]} ${points[0][1]}`;

    for (let i = 1; i < points.length; i++) {
      const [x0, y0] = points[i - 1];
      const [x1, y1] = points[i];
      const mid = (x0 + x1) / 2;

      path += ` C ${mid} ${y0}, ${mid} ${y1}, ${x1} ${y1}`;
    }

    return path;
  }

  function renderEqGraph(plugin) {
    if (!pluginEditorControls) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const labels = ['LOW','LOW-MID','MID','HIGH-MID','HIGH'];

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>5-BAND PARAMETRIC EQ</span>
            <strong>Drag a node horizontally for frequency and vertically for gain.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-eq>Flat</button>
        </div>

        <svg
          class="daw-plugin-graph daw-eq-graph"
          data-eq-graph
          viewBox="0 0 764 300"
          role="img"
          aria-label="Interactive five band equalizer graph"
        >
          <defs>
            <linearGradient id="eqFillGradient" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stop-color="currentColor" stop-opacity=".22"/>
              <stop offset="100%" stop-color="currentColor" stop-opacity="0"/>
            </linearGradient>
          </defs>

          <g class="daw-graph-grid">
            ${[20,50,100,250,500,1000,2500,5000,10000,20000]
              .map(f => `
                <line x1="${eqFrequencyToX(f)}" y1="20" x2="${eqFrequencyToX(f)}" y2="260"></line>
                <text x="${eqFrequencyToX(f)}" y="282">${formatFrequency(f)}</text>
              `).join('')}
            ${[-18,-12,-6,0,6,12,18]
              .map(g => `
                <line x1="42" y1="${eqGainToY(g)}" x2="722" y2="${eqGainToY(g)}"></line>
                <text x="8" y="${eqGainToY(g)+4}">${g > 0 ? '+' : ''}${g}</text>
              `).join('')}
          </g>

          <path
            class="daw-eq-fill"
            data-eq-fill
            d="${eqCurvePath(plugin)} L 722 ${eqGainToY(0)} L 42 ${eqGainToY(0)} Z"
          ></path>

          <path
            class="daw-eq-curve"
            data-eq-curve
            d="${eqCurvePath(plugin)}"
          ></path>

          ${frequencyKeys.map((frequencyKey, index) => `
            <g
              class="daw-eq-node"
              data-eq-node="${index}"
              tabindex="0"
              role="slider"
              aria-label="${labels[index]} EQ band"
              aria-valuetext="${formatFrequency(plugin.params[frequencyKey])}, ${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB"
              transform="translate(${eqFrequencyToX(plugin.params[frequencyKey])} ${eqGainToY(plugin.params[gainKeys[index]])})"
            >
              <circle class="daw-eq-node-halo" r="14"></circle>
              <circle class="daw-eq-node-core" r="7"></circle>
              <text x="0" y="-20">${index + 1}</text>
            </g>
          `).join('')}
        </svg>

        <div class="daw-eq-node-readouts">
          ${frequencyKeys.map((frequencyKey,index) => `
            <button
              type="button"
              data-eq-focus="${index}"
              class="daw-eq-readout"
            >
              <strong>${labels[index]}</strong>
              <span data-eq-frequency="${index}">${formatFrequency(plugin.params[frequencyKey])}</span>
              <em data-eq-gain="${index}">${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB</em>
            </button>
          `).join('')}
        </div>
      </div>
    `;

    bindEqGraph(plugin);
  }

  function updateEqGraphDom(plugin) {
    const graph = pluginEditorControls?.querySelector('[data-eq-graph]');
    if (!graph) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const curve = eqCurvePath(plugin);

    const curveEl = graph.querySelector('[data-eq-curve]');
    const fillEl = graph.querySelector('[data-eq-fill]');

    if (curveEl) {
      curveEl.setAttribute('d', curve);
    }

    if (fillEl) {
      fillEl.setAttribute(
        'd',
        `${curve} L 722 ${eqGainToY(0)} L 42 ${eqGainToY(0)} Z`
      );
    }

    graph.querySelectorAll('[data-eq-node]')
      .forEach((node,index) => {
        node.setAttribute(
          'transform',
          `translate(${eqFrequencyToX(plugin.params[frequencyKeys[index]])} ${eqGainToY(plugin.params[gainKeys[index]])})`
        );
        node.setAttribute(
          'aria-valuetext',
          `${formatFrequency(plugin.params[frequencyKeys[index]])}, ${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB`
        );

        const frequencyReadout =
          pluginEditorControls.querySelector(`[data-eq-frequency="${index}"]`);
        const gainReadout =
          pluginEditorControls.querySelector(`[data-eq-gain="${index}"]`);

        if (frequencyReadout) {
          frequencyReadout.textContent =
            formatFrequency(plugin.params[frequencyKeys[index]]);
        }

        if (gainReadout) {
          gainReadout.textContent =
            `${Number(plugin.params[gainKeys[index]]).toFixed(1)} dB`;
        }
      });
  }

  function bindEqGraph(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-eq-graph]');
    if (!svg) return;

    const frequencyKeys = ['f1','f2','f3','f4','f5'];
    const gainKeys = ['b1','b2','b3','b4','b5'];
    const frequencyRanges = [
      [40,180],
      [120,700],
      [500,2500],
      [1800,8000],
      [6000,18000]
    ];

    const applyNode = (index, x, y) => {
      const [minFrequency,maxFrequency] = frequencyRanges[index];

      plugin.params[frequencyKeys[index]] = Math.max(
        minFrequency,
        Math.min(maxFrequency, eqXToFrequency(x))
      );

      plugin.params[gainKeys[index]] = Math.round(
        Math.max(-18, Math.min(18, eqYToGain(y))) * 10
      ) / 10;

      updateEqGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    };

    svg.querySelectorAll('[data-eq-node]')
      .forEach((node,index) => {
        let pointerId = null;

        node.addEventListener('pointerdown', event => {
          if (event.button !== 0) return;

          pointerId = event.pointerId;
          node.setPointerCapture(pointerId);
          node.classList.add('dragging');
          event.preventDefault();
          event.stopPropagation();
        });

        node.addEventListener('pointermove', event => {
          if (event.pointerId !== pointerId) return;

          const point = svgPoint(event, svg);
          applyNode(index, point.x, point.y);
        });

        const finish = event => {
          if (event.pointerId !== pointerId) return;

          try {
            node.releasePointerCapture(pointerId);
          } catch (error) {}

          pointerId = null;
          node.classList.remove('dragging');
        };

        node.addEventListener('pointerup', finish);
        node.addEventListener('pointercancel', finish);

        node.addEventListener('keydown', event => {
          const frequencyKey = frequencyKeys[index];
          const gainKey = gainKeys[index];

          if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
            event.preventDefault();
            plugin.params[gainKey] = Math.max(
              -18,
              Math.min(
                18,
                Number(plugin.params[gainKey]) +
                (event.key === 'ArrowUp' ? 0.5 : -0.5)
              )
            );
          } else if (
            event.key === 'ArrowLeft' ||
            event.key === 'ArrowRight'
          ) {
            event.preventDefault();
            const direction = event.key === 'ArrowRight' ? 1 : -1;
            plugin.params[frequencyKey] *= Math.pow(2, direction / 24);

            const [minFrequency,maxFrequency] = frequencyRanges[index];
            plugin.params[frequencyKey] = Math.max(
              minFrequency,
              Math.min(maxFrequency, plugin.params[frequencyKey])
            );
          } else {
            return;
          }

          updateEqGraphDom(plugin);
          updateTrackPluginAudio(plugin);
          scheduleLocalSave();
        });
      });

    pluginEditorControls
      .querySelectorAll('[data-eq-focus]')
      .forEach(button => {
        button.addEventListener('click', () => {
          svg.querySelector(
            `[data-eq-node="${button.dataset.eqFocus}"]`
          )?.focus();
        });
      });

    pluginEditorControls
      .querySelector('[data-reset-eq]')
      ?.addEventListener('click', () => {
        const defaults = defaultPlugin('eq5').params;

        Object.assign(plugin.params, defaults);
        updateEqGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }

  function delayTimeToX(value) {
    return 50 + (
      (Math.max(0.02, Math.min(1.5, value)) - 0.02) /
      (1.5 - 0.02)
    ) * 610;
  }

  function delayXToTime(x) {
    const ratio = Math.max(0, Math.min(1, (x - 50) / 610));
    return 0.02 + ratio * (1.5 - 0.02);
  }

  function delayFeedbackToY(value) {
    return 250 - (
      Math.max(0, Math.min(0.92, value)) / 0.92
    ) * 210;
  }

  function delayYToFeedback(y) {
    const ratio = Math.max(0, Math.min(1, (250 - y) / 210));
    return ratio * 0.92;
  }

  function delayMixToY(value) {
    return 250 - Math.max(0, Math.min(1, value)) * 210;
  }

  function delayYToMix(y) {
    return Math.max(0, Math.min(1, (250 - y) / 210));
  }

  function renderDelayGraph(plugin) {
    if (!pluginEditorControls) return;

    const timeX = delayTimeToX(plugin.params.time);
    const feedbackY = delayFeedbackToY(plugin.params.feedback);
    const mixY = delayMixToY(plugin.params.mix);

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>STEREO DELAY</span>
            <strong>Drag the echo node for time/feedback. Drag the wet node for mix.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-delay>Reset</button>
        </div>

        <svg
          class="daw-plugin-graph daw-delay-graph"
          data-delay-graph
          viewBox="0 0 764 300"
          role="img"
          aria-label="Interactive delay graph"
        >
          <g class="daw-graph-grid">
            ${[0.02,0.25,0.5,0.75,1.0,1.25,1.5]
              .map(t => `
                <line x1="${delayTimeToX(t)}" y1="40" x2="${delayTimeToX(t)}" y2="250"></line>
                <text x="${delayTimeToX(t)}" y="280">${t.toFixed(t < 0.1 ? 2 : 1)}s</text>
              `).join('')}
            ${[0,0.23,0.46,0.69,0.92]
              .map(f => `
                <line x1="50" y1="${delayFeedbackToY(f)}" x2="660" y2="${delayFeedbackToY(f)}"></line>
                <text x="10" y="${delayFeedbackToY(f)+4}">${Math.round(f*100)}%</text>
              `).join('')}
          </g>

          <path
            class="daw-delay-tail"
            data-delay-tail
            d="M 50 250 C ${timeX*0.45} 250, ${timeX*0.75} ${feedbackY}, ${timeX} ${feedbackY}"
          ></path>

          ${[1,2,3,4].map(n => `
            <circle
              class="daw-delay-repeat"
              data-delay-repeat="${n}"
              cx="${Math.min(660, timeX + n * 55)}"
              cy="${Math.min(250, feedbackY + n * (250-feedbackY)/5)}"
              r="${Math.max(2.5, 7 - n)}"
            ></circle>
          `).join('')}

          <g
            class="daw-delay-node"
            data-delay-node
            tabindex="0"
            role="slider"
            aria-label="Delay time and feedback"
            aria-valuetext="${Number(plugin.params.time).toFixed(2)} seconds, ${Math.round(plugin.params.feedback*100)} percent feedback"
            transform="translate(${timeX} ${feedbackY})"
          >
            <circle class="daw-delay-node-halo" r="15"></circle>
            <circle class="daw-delay-node-core" r="8"></circle>
            <text x="0" y="-20">ECHO</text>
          </g>

          <g class="daw-delay-mix-axis">
            <line x1="700" y1="40" x2="700" y2="250"></line>
            <text x="700" y="280">WET</text>
          </g>

          <g
            class="daw-delay-node daw-delay-mix-node"
            data-delay-mix-node
            tabindex="0"
            role="slider"
            aria-label="Delay wet mix"
            aria-valuetext="${Math.round(plugin.params.mix*100)} percent wet"
            transform="translate(700 ${mixY})"
          >
            <circle class="daw-delay-node-halo" r="13"></circle>
            <circle class="daw-delay-node-core" r="7"></circle>
          </g>
        </svg>

        <div class="daw-delay-readouts">
          <button type="button" data-delay-focus="echo">
            <strong>TIME</strong>
            <span data-delay-time>${Number(plugin.params.time).toFixed(2)} s</span>
          </button>
          <button type="button" data-delay-focus="echo">
            <strong>FEEDBACK</strong>
            <span data-delay-feedback>${Math.round(plugin.params.feedback*100)}%</span>
          </button>
          <button type="button" data-delay-focus="mix">
            <strong>WET MIX</strong>
            <span data-delay-mix>${Math.round(plugin.params.mix*100)}%</span>
          </button>
        </div>
      </div>
    `;

    bindDelayGraph(plugin);
  }

  function updateDelayGraphDom(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-delay-graph]');
    if (!svg) return;

    const timeX = delayTimeToX(plugin.params.time);
    const feedbackY = delayFeedbackToY(plugin.params.feedback);
    const mixY = delayMixToY(plugin.params.mix);

    const node = svg.querySelector('[data-delay-node]');
    const mixNode = svg.querySelector('[data-delay-mix-node]');

    node?.setAttribute(
      'transform',
      `translate(${timeX} ${feedbackY})`
    );
    node?.setAttribute(
      'aria-valuetext',
      `${Number(plugin.params.time).toFixed(2)} seconds, ${Math.round(plugin.params.feedback*100)} percent feedback`
    );

    mixNode?.setAttribute(
      'transform',
      `translate(700 ${mixY})`
    );
    mixNode?.setAttribute(
      'aria-valuetext',
      `${Math.round(plugin.params.mix*100)} percent wet`
    );

    svg.querySelector('[data-delay-tail]')?.setAttribute(
      'd',
      `M 50 250 C ${timeX*0.45} 250, ${timeX*0.75} ${feedbackY}, ${timeX} ${feedbackY}`
    );

    svg.querySelectorAll('[data-delay-repeat]')
      .forEach((circle,index) => {
        const n = index + 1;
        circle.setAttribute(
          'cx',
          Math.min(660, timeX + n * 55)
        );
        circle.setAttribute(
          'cy',
          Math.min(
            250,
            feedbackY + n * (250-feedbackY)/5
          )
        );
      });

    const timeReadout =
      pluginEditorControls.querySelector('[data-delay-time]');
    const feedbackReadout =
      pluginEditorControls.querySelector('[data-delay-feedback]');
    const mixReadout =
      pluginEditorControls.querySelector('[data-delay-mix]');

    if (timeReadout) {
      timeReadout.textContent =
        `${Number(plugin.params.time).toFixed(2)} s`;
    }
    if (feedbackReadout) {
      feedbackReadout.textContent =
        `${Math.round(plugin.params.feedback*100)}%`;
    }
    if (mixReadout) {
      mixReadout.textContent =
        `${Math.round(plugin.params.mix*100)}%`;
    }
  }

  function bindDelayGraph(plugin) {
    const svg = pluginEditorControls?.querySelector('[data-delay-graph]');
    if (!svg) return;

    const echoNode = svg.querySelector('[data-delay-node]');
    const mixNode = svg.querySelector('[data-delay-mix-node]');

    if (echoNode) {
      let pointerId = null;

      echoNode.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        pointerId = event.pointerId;
        echoNode.setPointerCapture(pointerId);
        echoNode.classList.add('dragging');
        event.preventDefault();
      });

      echoNode.addEventListener('pointermove', event => {
        if (event.pointerId !== pointerId) return;
        const point = svgPoint(event, svg);

        plugin.params.time = Math.round(
          delayXToTime(point.x) * 100
        ) / 100;
        plugin.params.feedback = Math.round(
          delayYToFeedback(point.y) * 100
        ) / 100;

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });

      const finish = event => {
        if (event.pointerId !== pointerId) return;
        try {
          echoNode.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        echoNode.classList.remove('dragging');
      };

      echoNode.addEventListener('pointerup', finish);
      echoNode.addEventListener('pointercancel', finish);

      echoNode.addEventListener('keydown', event => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          plugin.params.time = Math.max(
            0.02,
            Math.min(
              1.5,
              Number(plugin.params.time) +
              (event.key === 'ArrowRight' ? 0.01 : -0.01)
            )
          );
        } else if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
          event.preventDefault();
          plugin.params.feedback = Math.max(
            0,
            Math.min(
              0.92,
              Number(plugin.params.feedback) +
              (event.key === 'ArrowUp' ? 0.01 : -0.01)
            )
          );
        } else {
          return;
        }

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
    }

    if (mixNode) {
      let pointerId = null;

      mixNode.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;
        pointerId = event.pointerId;
        mixNode.setPointerCapture(pointerId);
        mixNode.classList.add('dragging');
        event.preventDefault();
      });

      mixNode.addEventListener('pointermove', event => {
        if (event.pointerId !== pointerId) return;
        const point = svgPoint(event, svg);

        plugin.params.mix = Math.round(
          delayYToMix(point.y) * 100
        ) / 100;

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });

      const finish = event => {
        if (event.pointerId !== pointerId) return;
        try {
          mixNode.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        mixNode.classList.remove('dragging');
      };

      mixNode.addEventListener('pointerup', finish);
      mixNode.addEventListener('pointercancel', finish);

      mixNode.addEventListener('keydown', event => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
          return;
        }

        event.preventDefault();
        plugin.params.mix = Math.max(
          0,
          Math.min(
            1,
            Number(plugin.params.mix) +
            (event.key === 'ArrowUp' ? 0.02 : -0.02)
          )
        );

        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
    }

    pluginEditorControls
      .querySelectorAll('[data-delay-focus]')
      .forEach(button => {
        button.addEventListener('click', () => {
          if (button.dataset.delayFocus === 'mix') {
            mixNode?.focus();
          } else {
            echoNode?.focus();
          }
        });
      });

    pluginEditorControls
      .querySelector('[data-reset-delay]')
      ?.addEventListener('click', () => {
        Object.assign(
          plugin.params,
          defaultPlugin('delay').params
        );
        updateDelayGraphDom(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }

  function compressorDbToX(db) {
    return 52 + (
      (clamp(db,-60,0) + 60) / 60
    ) * 620;
  }

  function compressorDbToY(db) {
    return 252 - (
      (clamp(db,-60,0) + 60) / 60
    ) * 212;
  }

  function compressorCurvePath(plugin) {
    const threshold = Number(plugin.params.threshold);
    const ratio = Number(plugin.params.ratio);
    const makeup = Number(plugin.params.makeup || 0);
    const points = [];

    for (let db = -60; db <= 0; db += 2) {
      const out = db <= threshold
        ? db + makeup
        : threshold +
          (db - threshold) / ratio +
          makeup;

      points.push([
        compressorDbToX(db),
        compressorDbToY(out)
      ]);
    }

    return points.map(
      ([x,y],index) =>
        `${index === 0 ? 'M' : 'L'} ${x} ${y}`
    ).join(' ');
  }

  function renderCompressorGraph(plugin) {
    if (!pluginEditorControls) return;

    const thresholdX =
      compressorDbToX(plugin.params.threshold);
    const ratioY = 244 - (
      (clamp(plugin.params.ratio,1,20) - 1) /
      19
    ) * 176;
    const makeupY = 244 - (
      (clamp(plugin.params.makeup,-6,18) + 6) /
      24
    ) * 176;

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>DYNAMICS COMPRESSOR</span>
            <strong>Drag THRESH left/right and up/down for threshold/ratio. Drag MAKEUP vertically.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-compressor>Reset</button>
        </div>

        <svg
          class="daw-plugin-graph daw-compressor-graph"
          data-compressor-graph
          viewBox="0 0 764 300"
          aria-label="Interactive compressor graph"
        >
          <g class="daw-graph-grid">
            ${[-60,-48,-36,-24,-12,0].map(db => `
              <line x1="${compressorDbToX(db)}" y1="40" x2="${compressorDbToX(db)}" y2="252"></line>
              <line x1="52" y1="${compressorDbToY(db)}" x2="672" y2="${compressorDbToY(db)}"></line>
              <text x="${compressorDbToX(db)}" y="278">${db}</text>
            `).join('')}
          </g>

          <line class="daw-compressor-unity" x1="52" y1="252" x2="672" y2="40"></line>
          <path class="daw-compressor-curve" data-compressor-curve d="${compressorCurvePath(plugin)}"></path>

          <g
            class="daw-compressor-node"
            data-compressor-node
            transform="translate(${thresholdX} ${ratioY})"
            tabindex="0"
          >
            <circle r="14"></circle>
            <circle r="7"></circle>
            <text x="0" y="-20">THRESH</text>
          </g>

          <g class="daw-compressor-makeup-axis">
            <line x1="710" y1="68" x2="710" y2="244"></line>
            <text x="710" y="276">MAKEUP</text>
          </g>

          <g
            class="daw-compressor-node daw-compressor-makeup-node"
            data-compressor-makeup-node
            transform="translate(710 ${makeupY})"
            tabindex="0"
          >
            <circle r="12"></circle>
            <circle r="6"></circle>
          </g>
        </svg>

        <div class="daw-plugin-secondary-controls">
          <label>KNEE <input type="range" min="0" max="40" step="1" value="${plugin.params.knee}" data-comp-param="knee"><output>${Number(plugin.params.knee).toFixed(0)} dB</output></label>
          <label>ATTACK <input type="range" min="0.001" max="1" step="0.001" value="${plugin.params.attack}" data-comp-param="attack"><output>${Math.round(plugin.params.attack*1000)} ms</output></label>
          <label>RELEASE <input type="range" min="0.01" max="3" step="0.01" value="${plugin.params.release}" data-comp-param="release"><output>${Number(plugin.params.release).toFixed(2)} s</output></label>
        </div>

        <div class="daw-delay-readouts">
          <button type="button"><strong>THRESHOLD</strong><span data-comp-threshold>${Number(plugin.params.threshold).toFixed(1)} dB</span></button>
          <button type="button"><strong>RATIO</strong><span data-comp-ratio>${Number(plugin.params.ratio).toFixed(1)}:1</span></button>
          <button type="button"><strong>MAKEUP</strong><span data-comp-makeup>${Number(plugin.params.makeup).toFixed(1)} dB</span></button>
        </div>
      </div>
    `;

    bindCompressorGraph(plugin);
  }

  function updateCompressorGraphDom(plugin) {
    const svg = pluginEditorControls?.querySelector(
      '[data-compressor-graph]'
    );
    if (!svg) return;

    const thresholdX =
      compressorDbToX(plugin.params.threshold);
    const ratioY = 244 - (
      (clamp(plugin.params.ratio,1,20) - 1) /
      19
    ) * 176;
    const makeupY = 244 - (
      (clamp(plugin.params.makeup,-6,18) + 6) /
      24
    ) * 176;

    svg.querySelector('[data-compressor-node]')
      ?.setAttribute(
        'transform',
        `translate(${thresholdX} ${ratioY})`
      );

    svg.querySelector('[data-compressor-makeup-node]')
      ?.setAttribute(
        'transform',
        `translate(710 ${makeupY})`
      );

    svg.querySelector('[data-compressor-curve]')
      ?.setAttribute(
        'd',
        compressorCurvePath(plugin)
      );

    const threshold =
      pluginEditorControls.querySelector(
        '[data-comp-threshold]'
      );
    const ratio =
      pluginEditorControls.querySelector(
        '[data-comp-ratio]'
      );
    const makeup =
      pluginEditorControls.querySelector(
        '[data-comp-makeup]'
      );

    if (threshold) {
      threshold.textContent =
        `${Number(plugin.params.threshold).toFixed(1)} dB`;
    }
    if (ratio) {
      ratio.textContent =
        `${Number(plugin.params.ratio).toFixed(1)}:1`;
    }
    if (makeup) {
      makeup.textContent =
        `${Number(plugin.params.makeup).toFixed(1)} dB`;
    }
  }

  function bindCompressorGraph(plugin) {
    const svg = pluginEditorControls?.querySelector(
      '[data-compressor-graph]'
    );
    if (!svg) return;

    const node = svg.querySelector(
      '[data-compressor-node]'
    );
    const makeupNode = svg.querySelector(
      '[data-compressor-makeup-node]'
    );

    const bindNode = (
      element,
      onMove
    ) => {
      if (!element) return;

      let pointerId = null;

      element.addEventListener(
        'pointerdown',
        event => {
          if (event.button !== 0) return;
          pointerId = event.pointerId;
          element.setPointerCapture(pointerId);
          element.classList.add('dragging');
          event.preventDefault();
        }
      );

      element.addEventListener(
        'pointermove',
        event => {
          if (event.pointerId !== pointerId) {
            return;
          }
          onMove(svgPoint(event,svg));
        }
      );

      const finish = event => {
        if (event.pointerId !== pointerId) {
          return;
        }
        try {
          element.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        element.classList.remove('dragging');
      };

      element.addEventListener('pointerup',finish);
      element.addEventListener('pointercancel',finish);
    };

    bindNode(node,point => {
      plugin.params.threshold = Math.round(
        clamp(
          ((point.x - 52) / 620) * 60 - 60,
          -60,
          0
        ) * 10
      ) / 10;

      plugin.params.ratio = Math.round(
        (
          1 +
          clamp((244 - point.y) / 176,0,1) * 19
        ) * 10
      ) / 10;

      updateCompressorGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });

    bindNode(makeupNode,point => {
      plugin.params.makeup = Math.round(
        (
          -6 +
          clamp((244 - point.y) / 176,0,1) * 24
        ) * 10
      ) / 10;

      updateCompressorGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });

    pluginEditorControls
      .querySelectorAll('[data-comp-param]')
      .forEach(input => {
        input.addEventListener('input',() => {
          const key = input.dataset.compParam;
          plugin.params[key] = Number(input.value);
          const output = input.parentElement
            ?.querySelector('output');

          if (output) {
            output.textContent = key === 'attack'
              ? `${Math.round(plugin.params[key] * 1000)} ms`
              : key === 'release'
                ? `${plugin.params[key].toFixed(2)} s`
                : `${plugin.params[key].toFixed(0)} dB`;
          }

          updateTrackPluginAudio(plugin);
          scheduleLocalSave();
        });
      });

    pluginEditorControls
      .querySelector('[data-reset-compressor]')
      ?.addEventListener('click',() => {
        Object.assign(
          plugin.params,
          defaultPlugin('compressor').params
        );
        renderCompressorGraph(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }

  function reverbDecayToX(value) {
    return 52 + (
      (clamp(value,0.2,8) - 0.2) /
      7.8
    ) * 610;
  }

  function reverbMixToY(value) {
    return 244 - clamp(value,0,1) * 176;
  }

  function reverbSizeToX(value) {
    return 52 + (
      (clamp(value,0.25,2.5) - 0.25) /
      2.25
    ) * 610;
  }

  function reverbDampingToY(value) {
    const min = 800;
    const max = 20000;
    const ratio =
      Math.log(clamp(value,min,max) / min) /
      Math.log(max / min);
    return 244 - ratio * 176;
  }

  function renderReverbGraph(plugin) {
    if (!pluginEditorControls) return;

    const tailX =
      reverbDecayToX(plugin.params.decay);
    const mixY =
      reverbMixToY(plugin.params.mix);
    const roomX =
      reverbSizeToX(plugin.params.size);
    const dampingY =
      reverbDampingToY(plugin.params.damping);

    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div>
            <span>ALGORITHMIC ROOM</span>
            <strong>Drag TAIL for decay/mix, ROOM for size, and DAMP vertically for tone.</strong>
          </div>
          <button type="button" class="daw-small-button" data-reset-reverb>Reset</button>
        </div>

        <svg
          class="daw-plugin-graph daw-reverb-graph"
          data-reverb-graph
          viewBox="0 0 764 300"
          aria-label="Interactive reverb graph"
        >
          <g class="daw-graph-grid">
            ${[0.2,1,2,3,4,6,8].map(t => `
              <line x1="${reverbDecayToX(t)}" y1="68" x2="${reverbDecayToX(t)}" y2="244"></line>
              <text x="${reverbDecayToX(t)}" y="278">${t}s</text>
            `).join('')}
            ${[0,.25,.5,.75,1].map(m => `
              <line x1="52" y1="${reverbMixToY(m)}" x2="662" y2="${reverbMixToY(m)}"></line>
            `).join('')}
          </g>

          <path
            class="daw-reverb-tail"
            data-reverb-tail
            d="M 52 ${244 - plugin.params.mix * 80} C ${tailX * .45} ${110 - plugin.params.mix * 20}, ${tailX * .72} ${mixY}, ${tailX} ${mixY} L ${tailX + 40} 244"
          ></path>

          <g class="daw-reverb-node" data-reverb-tail-node transform="translate(${tailX} ${mixY})">
            <circle r="14"></circle><circle r="7"></circle><text y="-20">TAIL</text>
          </g>

          <g class="daw-reverb-node daw-reverb-room-node" data-reverb-room-node transform="translate(${roomX} 210)">
            <circle r="13"></circle><circle r="6"></circle><text y="-19">ROOM</text>
          </g>

          <line class="daw-reverb-damp-axis" x1="706" y1="68" x2="706" y2="244"></line>
          <g class="daw-reverb-node daw-reverb-damp-node" data-reverb-damp-node transform="translate(706 ${dampingY})">
            <circle r="12"></circle><circle r="6"></circle>
          </g>
        </svg>

        <div class="daw-delay-readouts">
          <button type="button"><strong>DECAY</strong><span data-reverb-decay>${Number(plugin.params.decay).toFixed(2)} s</span></button>
          <button type="button"><strong>ROOM SIZE</strong><span data-reverb-size>${Number(plugin.params.size).toFixed(2)}×</span></button>
          <button type="button"><strong>DAMPING</strong><span data-reverb-damping>${formatFrequency(plugin.params.damping)}</span></button>
          <button type="button"><strong>WET MIX</strong><span data-reverb-mix>${Math.round(plugin.params.mix*100)}%</span></button>
        </div>
      </div>
    `;

    bindReverbGraph(plugin);
  }

  function updateReverbGraphDom(plugin) {
    const svg = pluginEditorControls?.querySelector(
      '[data-reverb-graph]'
    );
    if (!svg) return;

    const tailX =
      reverbDecayToX(plugin.params.decay);
    const mixY =
      reverbMixToY(plugin.params.mix);
    const roomX =
      reverbSizeToX(plugin.params.size);
    const dampingY =
      reverbDampingToY(plugin.params.damping);

    svg.querySelector('[data-reverb-tail-node]')
      ?.setAttribute(
        'transform',
        `translate(${tailX} ${mixY})`
      );
    svg.querySelector('[data-reverb-room-node]')
      ?.setAttribute(
        'transform',
        `translate(${roomX} 210)`
      );
    svg.querySelector('[data-reverb-damp-node]')
      ?.setAttribute(
        'transform',
        `translate(706 ${dampingY})`
      );

    svg.querySelector('[data-reverb-tail]')
      ?.setAttribute(
        'd',
        `M 52 ${244 - plugin.params.mix * 80} C ${tailX * .45} ${110 - plugin.params.mix * 20}, ${tailX * .72} ${mixY}, ${tailX} ${mixY} L ${tailX + 40} 244`
      );

    const values = {
      decay:`${Number(plugin.params.decay).toFixed(2)} s`,
      size:`${Number(plugin.params.size).toFixed(2)}×`,
      damping:formatFrequency(plugin.params.damping),
      mix:`${Math.round(plugin.params.mix * 100)}%`
    };

    Object.entries(values).forEach(([key,value]) => {
      const el = pluginEditorControls.querySelector(
        `[data-reverb-${key}]`
      );
      if (el) el.textContent = value;
    });
  }

  function bindReverbGraph(plugin) {
    const svg = pluginEditorControls?.querySelector(
      '[data-reverb-graph]'
    );
    if (!svg) return;

    const bind = (selector,callback) => {
      const node = svg.querySelector(selector);
      if (!node) return;

      let pointerId = null;

      node.addEventListener('pointerdown',event => {
        if (event.button !== 0) return;
        pointerId = event.pointerId;
        node.setPointerCapture(pointerId);
        node.classList.add('dragging');
        event.preventDefault();
      });

      node.addEventListener('pointermove',event => {
        if (event.pointerId !== pointerId) return;
        callback(svgPoint(event,svg));
      });

      const finish = event => {
        if (event.pointerId !== pointerId) return;
        try {
          node.releasePointerCapture(pointerId);
        } catch (error) {}
        pointerId = null;
        node.classList.remove('dragging');
      };

      node.addEventListener('pointerup',finish);
      node.addEventListener('pointercancel',finish);
    };

    bind('[data-reverb-tail-node]',point => {
      plugin.params.decay = Math.round(
        (
          0.2 +
          clamp((point.x - 52) / 610,0,1) * 7.8
        ) * 100
      ) / 100;
      plugin.params.mix = Math.round(
        clamp((244 - point.y) / 176,0,1) * 100
      ) / 100;

      updateReverbGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });

    bind('[data-reverb-room-node]',point => {
      plugin.params.size = Math.round(
        (
          0.25 +
          clamp((point.x - 52) / 610,0,1) * 2.25
        ) * 100
      ) / 100;

      updateReverbGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });

    bind('[data-reverb-damp-node]',point => {
      const ratio = clamp(
        (244 - point.y) / 176,
        0,
        1
      );

      plugin.params.damping = Math.round(
        800 * Math.pow(20000 / 800,ratio)
      );

      updateReverbGraphDom(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });

    pluginEditorControls
      .querySelector('[data-reset-reverb]')
      ?.addEventListener('click',() => {
        Object.assign(
          plugin.params,
          defaultPlugin('reverb').params
        );
        renderReverbGraph(plugin);
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
  }


  function renderLimiterGraph(plugin) {
    if (!pluginEditorControls) return;
    pluginEditorControls.innerHTML = `
      <div class="daw-plugin-graph-shell">
        <div class="daw-plugin-graph-head">
          <div><span>STONEFELLOW MASTER LIMITER</span><strong>Brickwall-style final dynamics control for the master bus.</strong></div>
          <button type="button" class="daw-small-button" data-reset-limiter>Reset</button>
        </div>
        <div class="daw-plugin-secondary-controls daw-limiter-controls">
          <label>THRESHOLD <input type="range" min="-36" max="0" step="0.1" value="${plugin.params.threshold}" data-limit-param="threshold"><output>${Number(plugin.params.threshold).toFixed(1)} dB</output></label>
          <label>CEILING <input type="range" min="-12" max="0" step="0.1" value="${plugin.params.ceiling}" data-limit-param="ceiling"><output>${Number(plugin.params.ceiling).toFixed(1)} dB</output></label>
          <label>RELEASE <input type="range" min="0.02" max="2" step="0.01" value="${plugin.params.release}" data-limit-param="release"><output>${Number(plugin.params.release).toFixed(2)} s</output></label>
          <label>LOOKAHEAD <input type="range" min="0" max="0.05" step="0.001" value="${plugin.params.lookahead}" data-limit-param="lookahead"><output>${Math.round(Number(plugin.params.lookahead)*1000)} ms</output></label>
        </div>
      </div>`;

    pluginEditorControls.querySelectorAll('[data-limit-param]').forEach(input => {
      input.addEventListener('input',() => {
        const key = input.dataset.limitParam;
        plugin.params[key] = Number(input.value);
        const output = input.parentElement?.querySelector('output');
        if (output) {
          output.textContent = key === 'release'
            ? `${plugin.params[key].toFixed(2)} s`
            : key === 'lookahead'
              ? `${Math.round(plugin.params[key]*1000)} ms`
              : `${plugin.params[key].toFixed(1)} dB`;
        }
        updateTrackPluginAudio(plugin);
        scheduleLocalSave();
      });
    });

    pluginEditorControls.querySelector('[data-reset-limiter]')?.addEventListener('click',() => {
      Object.assign(plugin.params,defaultPlugin('limiter').params);
      renderLimiterGraph(plugin);
      updateTrackPluginAudio(plugin);
      scheduleLocalSave();
    });
  }

  function openPluginEditor(targetKey,index) {
    const target =
      pluginTargetByKey(targetKey);
    const plugin =
      target?.plugins?.[index];

    if (!target || !plugin) return;

    pluginTargetKey =
      target.pluginKey ||
      target.key;
    pluginEditIndex = index;

    if (pluginDirectoryTrack) {
      const importedFrom = plugin.source_name
        ? `<small>Imported from ${escapeHtml(plugin.source_name)}${plugin.source_preset ? ` · ${escapeHtml(plugin.source_preset)}` : ''}${plugin.mapping ? ` · ${escapeHtml(plugin.mapping.toUpperCase())} ${Math.round(Number(plugin.confidence || 0) * 100)}%` : ''}</small>`
        : '';

      pluginDirectoryTrack.innerHTML = `
        <span>CHANNEL</span>
        <strong>${escapeHtml(pluginTargetLabel(target))}</strong>
        ${importedFrom}
      `;
    }

    if (pluginEditorTitle) {
      pluginEditorTitle.textContent =
        pluginLabel(plugin);
    }

    if (pluginBypassButton) {
      pluginBypassButton.textContent =
        plugin.enabled
          ? 'Bypass'
          : 'Enable';

      pluginBypassButton.classList.toggle(
        'active',
        !plugin.enabled
      );
    }

    if (plugin.type === 'eq5') {
      renderEqGraph(plugin);
    } else if (plugin.type === 'delay') {
      renderDelayGraph(plugin);
    } else if (
      plugin.type === 'compressor'
    ) {
      renderCompressorGraph(plugin);
    } else if (plugin.type === 'limiter') {
      renderLimiterGraph(plugin);
    } else {
      renderReverbGraph(plugin);
    }

    document.getElementById(
      'pluginDirectoryGrid'
    )?.setAttribute('hidden','');

    if (pluginEditor) {
      pluginEditor.hidden = false;
    }

    openModal(pluginDirectoryDialog);
  }

  function addPluginToTarget(target,type) {
    if (
      !target ||
      target.plugins.length >= 6
    ) {
      return;
    }

    target.plugins.push(
      defaultPlugin(type)
    );

    renderPluginTargetList(target);

    if (context) {
      rebuildPluginTargetGraph(target);
    }

    scheduleLocalSave(0);

    openPluginEditor(
      target.pluginKey ||
      target.key,
      target.plugins.length - 1
    );
  }


  function rebuildMasterGraph() {
    if (
      !context ||
      !busInput ||
      !eqLow ||
      !eqMid ||
      !eqHigh ||
      !compressor ||
      !masterGain ||
      !masterAnalyser ||
      !masterLiveMixTap ||
      !dryGain ||
      !wetGain ||
      !reverb
    ) {
      return;
    }

    [
      busInput,
      eqLow,
      eqMid,
      eqHigh,
      compressor,
      masterGain,
      masterAnalyser,
      dryGain,
      wetGain,
      reverb
    ].forEach(node => {
      try {
        node.disconnect();
      } catch (error) {}
    });

    const masterTarget =
      fixedPluginTargets.master;

    disconnectPluginTargetNodes(
      masterTarget
    );

    let current = busInput;

    if (pluginState.eq) {
      current.connect(eqLow);
      eqLow.connect(eqMid);
      eqMid.connect(eqHigh);
      current = eqHigh;
    }

    if (pluginState.compressor) {
      current.connect(compressor);
      current = compressor;
    }

    connectPluginChain(
      masterTarget,
      current,
      masterGain
    );

    masterGain.connect(masterAnalyser);

    masterAnalyser.connect(dryGain);
    dryGain.connect(masterLiveMixTap);

    if (pluginState.reverb) {
      masterAnalyser.connect(reverb);
      reverb.connect(wetGain);
      wetGain.gain.value = 0.18;
      wetGain.connect(masterLiveMixTap);
    } else {
      wetGain.gain.value = 0;
    }

  }

  function clamp(value,min,max) {
    return Math.max(
      min,
      Math.min(max,Number(value))
    );
  }

  function normalizeAutomation(automation) {
    const source = (
      automation &&
      typeof automation === 'object'
    )
      ? automation
      : {};

    const specs = {
      volume:[0,1.5],
      pan:[-1,1],
      auxA:[0,1],
      auxB:[0,1]
    };

    const output = {};

    Object.entries(specs).forEach(([key,[min,max]]) => {
      const points = Array.isArray(source[key])
        ? source[key]
        : [];

      output[key] = points
        .slice(0,64)
        .map(point => ({
          t:clamp(point?.t ?? 0,0,duration),
          v:clamp(point?.v ?? 0,min,max)
        }))
        .sort((a,b) => a.t - b.t);
    });

    return output;
  }

  function applyStemSendAudio(stem,bus,value) {
    const gainNode = bus === 'a'
      ? stem.auxASendGain
      : stem.auxBSendGain;

    if (gainNode && context) {
      gainNode.gain.setTargetAtTime(
        clamp(value,0,1),
        context.currentTime,
        0.012
      );
    }
  }

  function setStemSend(stem,bus,value,persist = true) {
    if (!stem) return;

    const clean = clamp(value,0,1);
    stem.sends[bus] = clean;

    const input = bus === 'a'
      ? stem.auxSendA
      : stem.auxSendB;
    const output = bus === 'a'
      ? stem.auxSendAValue
      : stem.auxSendBValue;

    if (input) {
      input.value = String(clean);
    }

    if (output) {
      output.textContent = `${Math.round(clean * 100)}%`;
    }

    applyStemSendAudio(stem,bus,clean);

    if (persist) {
      scheduleLocalSave();
    }
  }

  function setReturnLevel(bus,value,persist = true) {
    const clean = clamp(value,0,1.5);
    const input = bus === 'a'
      ? auxReturnA
      : auxReturnB;
    const output = bus === 'a'
      ? auxReturnAValue
      : auxReturnBValue;
    const gain = bus === 'a'
      ? auxAReturnGain
      : auxBReturnGain;

    if (input) {
      input.value = String(clean);
    }

    if (output) {
      output.textContent = dbText(clean);
    }

    if (gain && context) {
      gain.gain.setTargetAtTime(
        clean,
        context.currentTime,
        0.012
      );
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function updateTrimKnob(stem) {
    if (!stem) return;

    const value = clamp(
      stem.trimDb,
      -12,
      12
    );

    const degrees =
      -135 +
      (
        (value + 12) /
        24
      ) *
      270;

    stem.trimKnob?.style.setProperty(
      '--knob-angle',
      `${degrees}deg`
    );

    stem.trimKnob?.setAttribute(
      'aria-valuenow',
      value.toFixed(1)
    );

    if (stem.trimValue) {
      stem.trimValue.textContent =
        `${value >= 0 ? '+' : ''}${value.toFixed(1)} dB`;
    }
  }

  function setTrackTrim(
    stem,
    value,
    persist = true
  ) {
    if (!stem) return;

    stem.trimDb = clamp(
      value,
      -12,
      12
    );

    if (stem.trim) {
      stem.trim.value =
        String(stem.trimDb);
    }

    updateTrimKnob(stem);

    if (
      stem.trimNode &&
      context
    ) {
      stem.trimNode.gain.setTargetAtTime(
        Math.pow(
          10,
          stem.trimDb / 20
        ),
        context.currentTime,
        0.008
      );
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function rebuildStemInputRouting(stem) {
    if (
      !context ||
      !stem.sourceNode ||
      !stem.clipGainNode ||
      !stem.trimNode ||
      !stem.gainNode
    ) {
      return;
    }

    [
      stem.sourceNode,
      stem.clipGainNode,
      stem.crossfadeSourceNode,
      stem.crossfadeGainNode,
      stem.trimNode
    ].forEach(node => {
      if (!node) return;

      try {
        node.disconnect();
      } catch (error) {}
    });

    stem.sourceNode.connect(
      stem.clipGainNode
    );

    stem.clipGainNode.connect(
      stem.trimNode
    );

    if (
      stem.crossfadeSourceNode &&
      stem.crossfadeGainNode
    ) {
      stem.crossfadeSourceNode.connect(
        stem.crossfadeGainNode
      );

      stem.crossfadeGainNode.connect(
        stem.trimNode
      );
    }

    stem.trimNode.connect(
      stem.gainNode
    );
  }


  function customBusById(id) {
    return customBuses.find(
      bus => bus.id === String(id || '')
    ) || null;
  }

  function groupTarget(group) {
    if (groupState[group]?.input) {
      return groupState[group].input;
    }

    const custom =
      customBusById(group);

    return custom?.input || busInput;
  }

  function routeStemToGroup(stem) {
    if (!context || !stem?.analyserNode) return;

    try {
      stem.analyserNode.disconnect();
    } catch (error) {}

    stem.analyserNode.connect(
      groupTarget(stem.group)
    );
  }

  function syncTrackRouteButton(stem) {
    if (!stem?.groupMenuButton) {
      return;
    }

    const selected = [
      ...(stem.groupSelect?.options || [])
    ].find(
      option =>
        option.value ===
        String(stem.group || 'direct')
    );

    const label =
      selected?.textContent?.trim() ||
      'DIRECT';

    stem.groupMenuButton.innerHTML =
      `${escapeHtml(label)} <i>⌄</i>`;

    stem.groupMenuButton.title =
      `Bus: ${label}`;
    stem.groupMenuButton.setAttribute(
      'aria-label',
      `${pluginTargetLabel(stem)} bus: ${label}`
    );
  }

  function closeTrackRouteMenu() {
    if (!trackRoutePopover) return;

    trackRoutePopover.hidden = true;

    stems.forEach(stem => {
      stem.groupMenuButton?.setAttribute(
        'aria-expanded',
        'false'
      );
    });

    openRouteStem = null;
  }

  function openTrackRouteMenu(stem) {
    closeLibraryCategoryMenu();

    if (
      !trackRoutePopover ||
      !stem?.groupMenuButton ||
      !stem.groupSelect
    ) {
      return;
    }

    openRouteStem = stem;

    trackRoutePopover.innerHTML = [
      ...stem.groupSelect.options
    ].map(option => `
      <button
        type="button"
        role="menuitemradio"
        aria-checked="${
          option.value === stem.group
            ? 'true'
            : 'false'
        }"
        data-route-value="${escapeHtml(option.value)}"
        class="${
          option.value === stem.group
            ? 'active'
            : ''
        }"
      >${escapeHtml(option.textContent || option.value)}</button>
    `).join('');

    trackRoutePopover
      .querySelectorAll('[data-route-value]')
      .forEach(button => {
        button.addEventListener(
          'click',
          () => {
            setTrackGroup(
              stem,
              button.dataset.routeValue
            );
            closeTrackRouteMenu();
          }
        );
      });

    trackRoutePopover.hidden = false;

    stem.groupMenuButton.setAttribute(
      'aria-expanded',
      'true'
    );

    const rect =
      stem.groupMenuButton
        .getBoundingClientRect();

    const menuWidth = Math.min(
      190,
      Math.max(
        142,
        trackRoutePopover.scrollWidth
      )
    );

    const menuHeight =
      trackRoutePopover.offsetHeight;

    // Always open toward the center/left side of the viewport.
    const left = Math.max(
      8,
      Math.min(
        window.innerWidth -
          menuWidth -
          8,
        rect.right - menuWidth
      )
    );

    const top = Math.max(
      8,
      Math.min(
        window.innerHeight -
          menuHeight -
          8,
        rect.top
      )
    );

    trackRoutePopover.style.left =
      `${Math.round(left)}px`;
    trackRoutePopover.style.top =
      `${Math.round(top)}px`;
    trackRoutePopover.style.width =
      `${Math.round(menuWidth)}px`;
  }

  function setTrackGroup(stem,group,persist = true) {
    if (!stem) return;

    const requested =
      String(group || 'direct');

    const clean = (
      [
        'direct',
        'vocals',
        'rhythm',
        'music'
      ].includes(requested) ||
      Boolean(customBusById(requested))
    )
      ? requested
      : 'direct';

    stem.group = clean;

    if (stem.groupSelect) {
      stem.groupSelect.value = clean;
    }

    syncTrackRouteButton(stem);

    if (context) {
      routeStemToGroup(stem);
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function updateGroupBus(group,persist = true) {
    const state = groupState[group];
    if (!state) return;

    const input = document.querySelector(
      `[data-group-volume="${group}"]`
    );
    const output = document.querySelector(
      `[data-group-volume-value="${group}"]`
    );
    const mute = document.querySelector(
      `[data-group-mute="${group}"]`
    );

    if (input) {
      input.value = String(state.volume);
    }

    if (output) {
      output.textContent = dbText(state.volume);
    }

    mute?.classList.toggle(
      'active',
      state.muted
    );

    if (state.gain && context) {
      state.gain.gain.setTargetAtTime(
        state.muted ? 0 : state.volume,
        context.currentTime,
        0.01
      );
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function customBusId() {
    return `bus-${Date.now()}-${Math.random()
      .toString(36)
      .slice(2,7)}`;
  }

  function cleanBusName(name) {
    const value = String(name || '')
      .trim()
      .replace(/\s+/g,' ');

    return (
      value ||
      `BUS ${customBuses.length + 1}`
    ).slice(0,32);
  }

  function updateTrackGroupOptions() {
    stems.forEach(stem => {
      const select = stem.groupSelect;
      if (!select) return;

      select
        .querySelectorAll(
          'option[data-custom-bus-option]'
        )
        .forEach(option => option.remove());

      customBuses.forEach(bus => {
        const option =
          document.createElement('option');

        option.value = bus.id;
        option.textContent = bus.name;
        option.dataset.customBusOption =
          bus.id;

        select.appendChild(option);
      });

      if (
        [
          'direct',
          'vocals',
          'rhythm',
          'music'
        ].includes(stem.group) ||
        customBusById(stem.group)
      ) {
        select.value = stem.group;
      } else {
        stem.group = 'direct';
        select.value = 'direct';
      }

      syncTrackRouteButton(stem);
    });

    updateTrackInspector();
  }

  function customBusMarkup(bus) {
    return `
      <article
        class="daw-channel daw-group-channel daw-custom-bus-channel"
        data-custom-bus="${escapeHtml(bus.id)}"
        data-plugin-target="${escapeHtml(bus.pluginKey)}"
      >
        <div class="daw-channel-head">
          <strong title="${escapeHtml(bus.name)}">${escapeHtml(bus.name)}</strong>
          <span>CUSTOM</span>
        </div>

        <div
          class="daw-universal-plugin-slot"
          data-universal-plugin-slot="${escapeHtml(bus.pluginKey)}"
        >
          <div
            class="daw-track-plugin-list"
            data-universal-plugin-list="${escapeHtml(bus.pluginKey)}"
          ></div>
          <button
            type="button"
            class="daw-add-track-plugin"
            data-add-universal-plugin="${escapeHtml(bus.pluginKey)}"
          >+ Plugin</button>
        </div>

        <div class="daw-custom-bus-actions">
          <button
            class="daw-group-mute"
            type="button"
            data-custom-bus-mute
          >MUTE</button>
          <button
            class="daw-custom-bus-delete"
            type="button"
            data-custom-bus-delete
            title="Delete bus"
            aria-label="Delete ${escapeHtml(bus.name)}"
          >×</button>
        </div>

        <div class="daw-group-meter">
          <span></span><span></span>
        </div>

        <label class="daw-fader-wrap daw-group-fader">
          <input
            class="daw-fader daw-group-volume-control"
            type="range"
            min="0"
            max="1.5"
            value="${bus.volume}"
            step="0.01"
            orient="vertical"
            data-custom-bus-volume
            aria-label="${escapeHtml(bus.name)} volume"
          >
          <output data-custom-bus-volume-value>${dbText(bus.volume)}</output>
        </label>

        <div class="daw-channel-number">+</div>
      </article>
    `;
  }

  function renderCustomBus(bus) {
    if (!mixerScroll || !customBusAnchor) {
      return;
    }

    bus.element?.remove();

    const wrapper =
      document.createElement('div');
    wrapper.innerHTML =
      customBusMarkup(bus).trim();

    const element =
      wrapper.firstElementChild;

    mixerScroll.insertBefore(
      element,
      customBusAnchor
    );

    bus.element = element;
    bus.pluginList =
      element.querySelector(
        `[data-universal-plugin-list="${bus.pluginKey}"]`
      );
    bus.addPluginButton =
      element.querySelector(
        `[data-add-universal-plugin="${bus.pluginKey}"]`
      );
    bus.volumeInput =
      element.querySelector(
        '[data-custom-bus-volume]'
      );
    bus.volumeOutput =
      element.querySelector(
        '[data-custom-bus-volume-value]'
      );
    bus.muteButton =
      element.querySelector(
        '[data-custom-bus-mute]'
      );
    bus.meterBars = [
      ...element.querySelectorAll(
        '.daw-group-meter span'
      )
    ];

    bus.volumeInput?.addEventListener(
      'input',
      () => {
        bus.volume = clamp(
          bus.volumeInput.value,
          0,
          1.5
        );
        updateCustomBus(bus);
      }
    );

    bus.muteButton?.addEventListener(
      'click',
      () => {
        bus.muted = !bus.muted;
        updateCustomBus(bus);
      }
    );

    element
      .querySelector(
        '[data-custom-bus-delete]'
      )
      ?.addEventListener(
        'click',
        () => {
          const confirmed =
            window.confirm(
              `Delete ${bus.name}? Tracks routed here will move to Direct.`
            );

          if (confirmed) {
            removeCustomBus(bus);
          }
        }
      );

    bus.addPluginButton?.addEventListener(
      'click',
      event => {
        event.stopPropagation();
        openPluginDirectory(
          bus.pluginKey
        );
      }
    );

    renderPluginTargetList(bus);
    updateCustomBus(bus,false);
  }

  function ensureCustomBusAudio(bus) {
    if (!context || !bus) return;

    if (!bus.input) {
      bus.input = context.createGain();
    }

    if (!bus.analyser) {
      bus.analyser =
        context.createAnalyser();
      bus.analyser.fftSize = 256;
      bus.analyser.smoothingTimeConstant =
        0.7;
      bus.data = new Uint8Array(
        bus.analyser.fftSize
      );
    }

    if (!bus.gain) {
      bus.gain = context.createGain();
    }

    bus.gain.gain.value =
      bus.muted ? 0 : bus.volume;
  }

  function updateCustomBus(
    bus,
    persist = true
  ) {
    if (!bus) return;

    if (bus.volumeInput) {
      bus.volumeInput.value =
        String(bus.volume);
    }

    if (bus.volumeOutput) {
      bus.volumeOutput.textContent =
        dbText(bus.volume);
    }

    bus.muteButton?.classList.toggle(
      'active',
      bus.muted
    );

    if (bus.gain && context) {
      bus.gain.gain.setTargetAtTime(
        bus.muted ? 0 : bus.volume,
        context.currentTime,
        0.01
      );
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function createCustomBus(
    name,
    saved = {},
    persist = true
  ) {
    const id = String(
      saved.id || customBusId()
    );

    if (
      customBuses.some(
        bus => bus.id === id
      )
    ) {
      return customBusById(id);
    }

    const bus = {
      id,
      pluginKey:id,
      key:id,
      kind:'custom-bus',
      label:cleanBusName(
        saved.name || name
      ),
      name:cleanBusName(
        saved.name || name
      ),
      volume:clamp(
        saved.volume ?? 1,
        0,
        1.5
      ),
      muted:Boolean(saved.muted),
      plugins:normalizeTrackPlugins(
        saved.plugins
      ),
      pluginNodes:[],
      pluginList:null,
      addPluginButton:null,
      element:null,
      volumeInput:null,
      volumeOutput:null,
      muteButton:null,
      meterBars:[],
      input:null,
      analyser:null,
      data:null,
      gain:null
    };

    customBuses.push(bus);
    renderCustomBus(bus);
    updateTrackGroupOptions();

    if (context) {
      ensureCustomBusAudio(bus);
      rebuildCustomBusPluginGraph(bus);
    }

    if (persist) {
      scheduleLocalSave(0);
    }

    return bus;
  }

  function removeCustomBus(bus) {
    if (!bus) return;

    stems.forEach(stem => {
      if (stem.group === bus.id) {
        setTrackGroup(
          stem,
          'direct',
          false
        );
      }
    });

    [
      bus.input,
      bus.analyser,
      bus.gain,
      ...(bus.pluginNodes || [])
    ].forEach(node => {
      if (!node) return;

      try {
        node.disconnect();
      } catch (error) {}
    });

    bus.element?.remove();

    customBuses = customBuses.filter(
      item => item !== bus
    );

    updateTrackGroupOptions();
    scheduleLocalSave(0);
  }

  function restoreCustomBuses(items) {
    const oldBusIds = new Set(
      customBuses.map(bus => bus.id)
    );

    stems.forEach(stem => {
      if (oldBusIds.has(stem.group)) {
        stem.group = 'direct';

        if (context) {
          routeStemToGroup(stem);
        }
      }
    });

    customBuses.forEach(bus => {
      bus.element?.remove();

      [
        bus.input,
        bus.analyser,
        bus.gain,
        ...(bus.pluginNodes || [])
      ].forEach(node => {
        if (!node) return;

        try {
          node.disconnect();
        } catch (error) {}
      });
    });

    customBuses = [];

    (Array.isArray(items) ? items : [])
      .slice(0,12)
      .forEach(item => {
        createCustomBus(
          item?.name,
          item,
          false
        );
      });

    updateTrackGroupOptions();
  }

  function ensureAudioGraph() {
    if (!AudioContextClass || context) return;

    context = new AudioContextClass();

    busInput = context.createGain();

    Object.keys(groupState).forEach(group => {
      const state = groupState[group];

      state.input = context.createGain();
      state.gain = context.createGain();
      state.analyser = context.createAnalyser();
      state.analyser.fftSize = 256;
      state.analyser.smoothingTimeConstant = 0.7;
      state.data = new Uint8Array(
        state.analyser.fftSize
      );

      state.gain.gain.value =
        state.muted ? 0 : state.volume;

      state.input.connect(state.analyser);
      state.analyser.connect(state.gain);
      state.gain.connect(busInput);
    });

    eqLow = context.createBiquadFilter();
    eqLow.type = 'lowshelf';
    eqLow.frequency.value = 140;
    eqLow.gain.value = 0;

    eqMid = context.createBiquadFilter();
    eqMid.type = 'peaking';
    eqMid.frequency.value = 1200;
    eqMid.Q.value = 0.8;
    eqMid.gain.value = 0;

    eqHigh = context.createBiquadFilter();
    eqHigh.type = 'highshelf';
    eqHigh.frequency.value = 5200;
    eqHigh.gain.value = 0;

    compressor = context.createDynamicsCompressor();
    compressor.threshold.value = -18;
    compressor.knee.value = 14;
    compressor.ratio.value = 2;
    compressor.attack.value = 0.012;
    compressor.release.value = 0.24;

    masterGain = context.createGain();
    masterGain.gain.value = Number(
      masterVolume?.value || 1
    );

    masterAnalyser = context.createAnalyser();
    masterAnalyser.fftSize = 512;
    masterLiveMixTap = context.createGain();
    masterLiveMixTap.gain.value = 1;
    // Keep this destination connection stable across master graph rebuilds;
    // live recorders attach to the same tap and must not be disconnected.
    masterLiveMixTap.connect(context.destination);
    masterAnalyser.smoothingTimeConstant = 0.72;
    masterLevelData = new Uint8Array(
      masterAnalyser.fftSize
    );

    dryGain = context.createGain();
    wetGain = context.createGain();

    reverb = context.createConvolver();
    reverb.buffer = makeImpulse(context);

    // AUX A: shared room reverb return.
    auxAInput = context.createGain();
    auxAConvolver = context.createConvolver();
    auxAConvolver.buffer = makeImpulse(
      context,
      1.9,
      2.4
    );
    auxAReturnGain = context.createGain();
    auxAReturnGain.gain.value = Number(
      auxReturnA?.value || 0.8
    );

    auxAReturnGain.connect(busInput);

    // AUX B: shared feedback delay return.
    auxBInput = context.createGain();
    auxBDelay = context.createDelay(2.0);
    auxBDelay.delayTime.value = 0.32;
    auxBFeedback = context.createGain();
    auxBFeedback.gain.value = 0.34;
    auxBReturnGain = context.createGain();
    auxBReturnGain.gain.value = Number(
      auxReturnB?.value || 0.7
    );

    auxBReturnGain.connect(busInput);

    stems.forEach(stem => {
      try {
        /*
         * Before the Web Audio graph exists, updateGains() may use the
         * HTMLMediaElement mute/volume properties as a fallback. Those native
         * values must be cleared once MediaElementSource owns level control or
         * a track muted/soloed before first Play can stay silent permanently.
         */
        stem.audio.muted = false;
        stem.audio.volume = 1;

        stem.sourceNode =
          context.createMediaElementSource(stem.audio);

        stem.clipGainNode =
          context.createGain();
        stem.clipGainNode.gain.value = 1;

        stem.crossfadeAudio =
          new Audio(
            String(
              stem.url ||
              stem.audio?.src ||
              ''
            )
          );
        stem.crossfadeAudio.preload =
          'auto';
        stem.crossfadeAudio.muted = false;
        stem.crossfadeAudio.volume = 1;

        stem.crossfadeSourceNode =
          context.createMediaElementSource(
            stem.crossfadeAudio
          );
        stem.crossfadeGainNode =
          context.createGain();
        stem.crossfadeGainNode.gain.value =
          0;

        stem.trimNode = context.createGain();
        stem.trimNode.gain.value = Math.pow(
          10,
          stem.trimDb / 20
        );

        stem.gainNode = context.createGain();

        stem.analyserNode = context.createAnalyser();
        stem.analyserNode.fftSize = 1024;
        stem.analyserNode.minDecibels = -96;
        stem.analyserNode.maxDecibels = -18;
        stem.analyserNode.smoothingTimeConstant = 0.68;
        stem.liveCaptureTap = context.createGain();
        stem.liveCaptureTap.gain.value = 1;
        stem.liveCaptureTap.connect(stem.analyserNode);
        stem.frequencyData = new Uint8Array(
          stem.analyserNode.frequencyBinCount
        );
        stem.timeDomainData = new Uint8Array(
          stem.analyserNode.fftSize
        );

        stem.auxASendGain = context.createGain();
        stem.auxASendGain.gain.value =
          Number(stem.sends.a || 0);
        stem.auxASendGain.connect(auxAInput);
        stem.liveCaptureTap.connect(stem.auxASendGain);

        stem.auxBSendGain = context.createGain();
        stem.auxBSendGain.gain.value =
          Number(stem.sends.b || 0);
        stem.auxBSendGain.connect(auxBInput);
        stem.liveCaptureTap.connect(stem.auxBSendGain);

        if (context.createStereoPanner) {
          stem.panNode =
            context.createStereoPanner();
          stem.panNode.pan.value = Number(
            stem.pan?.value || 0
          );
          stem.gainNode.connect(stem.panNode);
        }

        rebuildStemInputRouting(stem);
        rebuildTrackPluginGraph(stem);
        routeStemToGroup(stem);
      } catch (error) {
        console.error(
          'Stem audio graph failed',
          stem.id,
          error
        );
      }
    });

    rebuildAuxPluginGraph(
      fixedPluginTargets['aux-a']
    );
    rebuildAuxPluginGraph(
      fixedPluginTargets['aux-b']
    );

    [
      fixedPluginTargets['group-vocals'],
      fixedPluginTargets['group-rhythm'],
      fixedPluginTargets['group-music']
    ].forEach(target => {
      rebuildGroupPluginGraph(target);
    });

    customBuses.forEach(bus => {
      ensureCustomBusAudio(bus);
      rebuildCustomBusPluginGraph(bus);
    });

    rebuildMasterGraph();
    updateGains();

    libraryClips.forEach(clip => {
      ensureLibraryClipAudioGraph(
        clip
      );
    });

    applySessionTempoToMedia();

    stems.forEach(stem => {
      setStemSend(
        stem,
        'a',
        stem.sends.a,
        false
      );
      setStemSend(
        stem,
        'b',
        stem.sends.b,
        false
      );
      setTrackTrim(
        stem,
        stem.trimDb,
        false
      );
      setTrackGroup(
        stem,
        stem.group,
        false
      );
    });

    Object.keys(groupState).forEach(group => {
      updateGroupBus(group,false);
    });
  }


  function ensureSpectrumCanvasSize(stem) {
    const canvas = stem.spectrumCanvas;
    const ctx = stem.spectrumCanvasContext;

    if (!canvas || !ctx) {
      return false;
    }

    const rect = canvas.getBoundingClientRect();
    const dpr = Math.max(
      1,
      Math.min(
        2,
        Number(window.devicePixelRatio || 1)
      )
    );

    const width = Math.max(
      24,
      Math.round(rect.width * dpr)
    );
    const height = Math.max(
      80,
      Math.round(rect.height * dpr)
    );

    if (
      canvas.width !== width ||
      canvas.height !== height
    ) {
      canvas.width = width;
      canvas.height = height;
    }

    return true;
  }

  function drawSpectrumIdle(stem) {
    if (!ensureSpectrumCanvasSize(stem)) {
      return;
    }

    const canvas = stem.spectrumCanvas;
    const ctx = stem.spectrumCanvasContext;
    const width = canvas.width;
    const height = canvas.height;

    ctx.clearRect(0,0,width,height);

    const rowHeight = height / 8;

    for (let index = 0; index < 8; index++) {
      const y = index * rowHeight;
      const inset = Math.max(1,rowHeight * 0.22);
      const barHeight = Math.max(
        2,
        rowHeight - inset * 2
      );

      ctx.fillStyle = 'rgba(113,133,121,.13)';
      ctx.fillRect(
        2,
        y + inset,
        Math.max(2,width - 4),
        barHeight
      );

      ctx.fillStyle = 'rgba(116,164,82,.28)';
      ctx.fillRect(
        2,
        y + inset,
        Math.max(2,(width - 4) * 0.035),
        barHeight
      );
    }
  }

  function spectrumBandEnergy(
    data,
    binHz,
    centerFrequency
  ) {
    const lowerHz = Math.max(
      20,
      centerFrequency / Math.SQRT2
    );
    const upperHz = Math.max(
      lowerHz,
      centerFrequency * Math.SQRT2
    );

    const from = Math.max(
      0,
      Math.floor(lowerHz / binHz)
    );
    const to = Math.min(
      data.length - 1,
      Math.ceil(upperHz / binHz)
    );

    let sum = 0;
    let peak = 0;
    let count = 0;

    for (let index = from; index <= to; index++) {
      const value = data[index] / 255;
      sum += value * value;
      peak = Math.max(peak,value);
      count++;
    }

    const rms = count
      ? Math.sqrt(sum / count)
      : 0;

    return Math.max(
      rms * 1.4,
      peak * 0.78
    );
  }

  function spectrumSignalRms(timeDomainData) {
    if (!timeDomainData?.length) {
      return 0;
    }

    let sum = 0;

    for (
      let index = 0;
      index < timeDomainData.length;
      index++
    ) {
      const sample =
        (timeDomainData[index] - 128) / 128;
      sum += sample * sample;
    }

    return Math.sqrt(
      sum / timeDomainData.length
    );
  }

  function drawTrackSpectrum(stem,reset = false) {
    if (
      !stem.spectrumCanvas ||
      !stem.spectrumCanvasContext
    ) {
      return;
    }

    if (
      reset ||
      !context ||
      !stem.analyserNode ||
      !stem.frequencyData ||
      !stem.timeDomainData
    ) {
      stem.spectrumDisplayLevels.fill(0);
      stem.spectrumPeakLevels.fill(0);
      drawSpectrumIdle(stem);
      return;
    }

    stem.analyserNode.getByteFrequencyData(
      stem.frequencyData
    );
    stem.analyserNode.getByteTimeDomainData(
      stem.timeDomainData
    );

    if (!ensureSpectrumCanvasSize(stem)) {
      return;
    }

    const canvas = stem.spectrumCanvas;
    const ctx = stem.spectrumCanvasContext;
    const width = canvas.width;
    const height = canvas.height;

    const frequencies = [
      8000,
      4000,
      2000,
      1000,
      500,
      250,
      125,
      63
    ];

    const nyquist = context.sampleRate / 2;
    const binHz =
      nyquist /
      Math.max(1,stem.frequencyData.length);

    const signalRms = spectrumSignalRms(
      stem.timeDomainData
    );

    ctx.clearRect(0,0,width,height);

    const rowHeight = height / frequencies.length;
    const horizontalPadding = Math.max(2,width * 0.07);

    frequencies.forEach((frequency,index) => {
      let raw = spectrumBandEnergy(
        stem.frequencyData,
        binHz,
        frequency
      );

      // If a browser/decoder returns an unusually flat frequency frame,
      // the time-domain RMS still proves the stem is carrying signal.
      // This keeps the meter alive while preserving the per-band shape.
      if (raw < 0.006 && signalRms > 0.002) {
        raw = signalRms * (
          0.72 +
          ((7 - index) / 7) * 0.18
        );
      }

      let target = Math.max(
        0.015,
        Math.min(
          1,
          Math.pow(raw * 1.85,0.72)
        )
      );

      const previous =
        Number(
          stem.spectrumDisplayLevels[index] ||
          0
        );

      // Fast attack / slower release, like a real console meter.
      const smoothing =
        target > previous
          ? 0.62
          : 0.16;

      const display =
        previous +
        (target - previous) * smoothing;

      stem.spectrumDisplayLevels[index] =
        display;

      let peak = Math.max(
        Number(stem.spectrumPeakLevels[index] || 0) * 0.94,
        display
      );
      stem.spectrumPeakLevels[index] = peak;

      const y = index * rowHeight;
      const insetY = Math.max(
        1,
        rowHeight * 0.19
      );
      const barHeight = Math.max(
        2,
        rowHeight - insetY * 2
      );
      const railWidth = Math.max(
        4,
        width - horizontalPadding * 2
      );
      const activeWidth = Math.max(
        2,
        railWidth * display
      );

      ctx.fillStyle =
        'rgba(111,130,119,.14)';
      ctx.fillRect(
        horizontalPadding,
        y + insetY,
        railWidth,
        barHeight
      );

      const gradient = ctx.createLinearGradient(
        horizontalPadding,
        0,
        horizontalPadding + railWidth,
        0
      );
      gradient.addColorStop(
        0,
        'rgba(102,166,76,.96)'
      );
      gradient.addColorStop(
        0.70,
        'rgba(157,174,68,.98)'
      );
      gradient.addColorStop(
        0.86,
        'rgba(205,151,57,.98)'
      );
      gradient.addColorStop(
        1,
        'rgba(183,70,63,.98)'
      );

      ctx.fillStyle = gradient;
      ctx.fillRect(
        horizontalPadding,
        y + insetY,
        activeWidth,
        barHeight
      );

      const peakX = Math.min(
        horizontalPadding + railWidth - 1,
        horizontalPadding +
          railWidth * peak
      );

      ctx.fillStyle =
        'rgba(232,239,234,.78)';
      ctx.fillRect(
        peakX,
        y + insetY,
        1,
        barHeight
      );
    });

    const hasSignal = signalRms > 0.002;

    stem.spectrumCanvas.parentElement
      ?.classList.toggle(
        'has-signal',
        hasSignal
      );

    stem.spectrumCanvas.parentElement
      ?.setAttribute(
        'data-signal-level',
        signalRms.toFixed(4)
      );
  }

  function updateEqDisplays(reset = false) {
    stems.forEach(stem => {
      drawTrackSpectrum(
        stem,
        reset
      );
    });
  }


  function updateGroupMeters(reset = false) {
    Object.entries(groupState).forEach(
      ([group,state]) => {
        const bars = [
          ...document.querySelectorAll(
            `[data-group-channel="${group}"] .daw-group-meter span`
          )
        ];

        if (!bars.length) return;

        if (
          reset ||
          !state.analyser ||
          !state.data
        ) {
          bars.forEach(bar => {
            bar.style.setProperty(
              '--group-level',
              '2%'
            );
          });
          return;
        }

        state.analyser.getByteTimeDomainData(
          state.data
        );

        let sum = 0;

        for (
          let i = 0;
          i < state.data.length;
          i++
        ) {
          const sample =
            (state.data[i] - 128) / 128;
          sum += sample * sample;
        }

        const rms = Math.sqrt(
          sum / Math.max(1,state.data.length)
        );

        const percent = `${
          Math.max(
            2,
            Math.min(100,rms * 380)
          ).toFixed(1)
        }%`;

        bars.forEach(bar => {
          bar.style.setProperty(
            '--group-level',
            percent
          );
        });
      }
    );

    customBuses.forEach(bus => {
      const bars = bus.meterBars || [];

      if (!bars.length) return;

      if (
        reset ||
        !bus.analyser ||
        !bus.data
      ) {
        bars.forEach(bar => {
          bar.style.setProperty(
            '--group-level',
            '2%'
          );
        });
        return;
      }

      bus.analyser.getByteTimeDomainData(
        bus.data
      );

      let sum = 0;

      for (
        let i = 0;
        i < bus.data.length;
        i++
      ) {
        const sample =
          (bus.data[i] - 128) / 128;
        sum += sample * sample;
      }

      const rms = Math.sqrt(
        sum /
        Math.max(1,bus.data.length)
      );

      const percent = `${
        Math.max(
          2,
          Math.min(100,rms * 380)
        ).toFixed(1)
      }%`;

      bars.forEach(bar => {
        bar.style.setProperty(
          '--group-level',
          percent
        );
      });
    });
  }

  function updateMasterMeter(reset = false) {
    if (!masterMeterBars.length) return;

    if (
      reset ||
      !masterAnalyser ||
      !masterLevelData
    ) {
      masterMeterBars.forEach(bar => {
        bar.style.setProperty('--master-level', '2%');
      });
      return;
    }

    masterAnalyser.getByteTimeDomainData(masterLevelData);

    let sum = 0;

    for (let i = 0; i < masterLevelData.length; i++) {
      const sample = (masterLevelData[i] - 128) / 128;
      sum += sample * sample;
    }

    const rms = Math.sqrt(
      sum / Math.max(1, masterLevelData.length)
    );

    const level = Math.max(
      0.02,
      Math.min(1, rms * 3.8)
    );

    const percent = `${(level * 100).toFixed(1)}%`;

    masterMeterBars.forEach(bar => {
      bar.style.setProperty('--master-level', percent);
    });
  }

  function updateKnob(stem) {
    const value = Math.max(
      -1,
      Math.min(1, Number(stem.pan?.value || 0))
    );
    const degrees = -135 + ((value + 1) / 2) * 270;

    stem.knob?.style.setProperty('--knob-angle', `${degrees}deg`);
    stem.knob?.setAttribute('aria-valuenow', value.toFixed(2));

    if (stem.panOutput) {
      stem.panOutput.textContent = panText(value);
    }
  }

  function setStemPan(stem, value) {
    const pan = Math.max(-1, Math.min(1, Number(value || 0)));

    if (stem.pan) {
      stem.pan.value = String(pan);
    }

    if (stem.panNode && context) {
      stem.panNode.pan.setTargetAtTime(
        pan,
        context.currentTime,
        0.008
      );
    }

    updateKnob(stem);
    scheduleLocalSave();
  }

  function updateReadouts(stem) {
    if (stem.volumeOutput) {
      stem.volumeOutput.textContent = dbText(stem.userGain);
    }

    updateKnob(stem);
  }

  function updateGains() {
    const anySolo =
      stems.some(
        stem => stem.solo
      );
    const mixTime =
      playing
        ? globalPosition()
        : position;

    stems.forEach(stem => {
      const audible =
        !stem.muted &&
        (
          !anySolo ||
          stem.solo
        );

      const automatedVolume =
        automationValueAt(
          stem.automation.volume || [],
          mixTime,
          stem.userGain
        );

      const playbackSlots =
        stemPlaybackSlotsAt(
          stem,
          mixTime
        );

      const mainClipGain =
        playbackSlots.main
          ? clipEnvelopeGainAt(
              playbackSlots.main,
              mixTime
            )
          : 0;

      const crossfadeClipGain =
        playbackSlots.crossfade
          ? clipEnvelopeGainAt(
              playbackSlots.crossfade,
              mixTime
            )
          : 0;

      if (
        stem.clipGainNode &&
        context
      ) {
        stem.clipGainNode.gain
          .setTargetAtTime(
            mainClipGain,
            context.currentTime,
            0.006
          );
      }

      if (
        stem.crossfadeGainNode &&
        context
      ) {
        stem.crossfadeGainNode.gain
          .setTargetAtTime(
            crossfadeClipGain,
            context.currentTime,
            0.006
          );
      }

      const gain =
        audible
          ? Math.max(
              0,
              Number(
                automatedVolume || 0
              )
            )
          : 0;

      if (
        stem.gainNode &&
        context
      ) {
        /*
         * MediaElementSource is now authoritative. Never carry a pre-graph
         * native mute/volume state into Web Audio.
         */
        stem.audio.muted = false;
        stem.audio.volume = 1;

        if (stem.crossfadeAudio) {
          stem.crossfadeAudio.muted =
            false;
          stem.crossfadeAudio.volume =
            1;
        }

        stem.gainNode.gain
          .cancelScheduledValues(
            context.currentTime
          );

        stem.gainNode.gain
          .setTargetAtTime(
            gain,
            context.currentTime,
            0.004
          );
      } else {
        /*
         * Pre-graph fallback only. ensureAudioGraph() clears these properties
         * before playback routing is created.
         */
        stem.audio.volume =
          Math.max(
            0,
            Math.min(
              1,
              Number(
                automatedVolume || 0
              )
            )
          );
        stem.audio.muted =
          !audible;
      }

      [
        stem.leftRow,
        stem.mixer,
        stem.arrangeRow
      ].forEach(el => {
        el?.classList.toggle(
          'muted',
          stem.muted
        );
        el?.classList.toggle(
          'soloed',
          stem.solo
        );
      });

      stem.muteButtons
        .forEach(button => {
          button.classList.toggle(
            'active',
            stem.muted
          );
          button.setAttribute(
            'aria-pressed',
            stem.muted
              ? 'true'
              : 'false'
          );
        });

      stem.soloButtons
        .forEach(button => {
          button.classList.toggle(
            'active',
            stem.solo
          );
          button.setAttribute(
            'aria-pressed',
            stem.solo
              ? 'true'
              : 'false'
          );
        });

      updateReadouts(stem);
    });

    updateTrackInspector();
    scheduleLocalSave();
  }


  function waveformFromAudioBuffer(
    buffer,
    pointCount = 1800
  ) {
    if (!buffer || buffer.length < 1) {
      return null;
    }

    const points = Math.max(
      128,
      Math.min(2400,pointCount)
    );
    const channels = Math.max(
      1,
      buffer.numberOfChannels || 1
    );
    const frames = Math.max(
      1,
      buffer.length
    );
    const mins = new Array(points).fill(0);
    const maxs = new Array(points).fill(0);
    const channelData = [];

    for (
      let channel = 0;
      channel < channels;
      channel++
    ) {
      channelData.push(
        buffer.getChannelData(channel)
      );
    }

    for (
      let bucket = 0;
      bucket < points;
      bucket++
    ) {
      const start = Math.floor(
        (bucket / points) * frames
      );
      const end = Math.max(
        start + 1,
        Math.floor(
          ((bucket + 1) / points) * frames
        )
      );
      const count = Math.max(
        1,
        end - start
      );
      const samples = Math.min(
        192,
        count
      );
      const step = Math.max(
        1,
        count / samples
      );
      let minValue = 0;
      let maxValue = 0;

      for (
        let sampleIndex = 0;
        sampleIndex < samples;
        sampleIndex++
      ) {
        const frame = Math.min(
          frames - 1,
          start +
            Math.floor(
              sampleIndex * step
            )
        );
        let frameMin = 0;
        let frameMax = 0;

        for (
          let channel = 0;
          channel < channels;
          channel++
        ) {
          const value =
            channelData[channel][frame] || 0;

          frameMin = Math.min(
            frameMin,
            value
          );
          frameMax = Math.max(
            frameMax,
            value
          );
        }

        minValue = Math.min(
          minValue,
          frameMin
        );
        maxValue = Math.max(
          maxValue,
          frameMax
        );
      }

      mins[bucket] = minValue;
      maxs[bucket] = maxValue;
    }

    return {
      mins,
      maxs,
      duration:Number(buffer.duration || 0),
      sampleRate:Number(buffer.sampleRate || 0),
      channels
    };
  }

  async function browserDecodeStemWaveform(stem) {
    if (!stem?.url || !AudioContextClass) {
      return null;
    }

    waveformDecodeContext ||= new AudioContextClass();

    const response = await fetch(
      stem.url,
      {
        credentials:'same-origin',
        cache:stem.waveformVersion
          ? 'no-store'
          : 'force-cache'
      }
    );

    if (!response.ok) {
      throw new Error(
        `Waveform media request failed (${response.status}).`
      );
    }

    const bytes =
      await response.arrayBuffer();

    const buffer =
      await waveformDecodeContext
        .decodeAudioData(
          bytes.slice(0)
        );

    return waveformFromAudioBuffer(
      buffer,
      2400
    );
  }

  async function loadStemWaveform(stem) {
    if (
      !stem ||
      stem.waveformData ||
      stem.waveformLoading
    ) {
      return;
    }

    stem.waveformLoading = true;
    stem.waveformError = false;

    try {
      let waveform = null;

      if (waveformEndpoint) {
        const query =
          `${waveformEndpoint}` +
          `${waveformEndpoint.includes('?') ? '&' : '?'}` +
          `id=${encodeURIComponent(stem.id)}` +
          `&points=2400` +
          `&v=${encodeURIComponent(
            stem.waveformVersion || 0
          )}`;

        const response = await fetch(
          query,
          {
            credentials:'same-origin',
            cache:stem.waveformVersion
              ? 'no-store'
              : 'force-cache'
          }
        );

        const payload = await response
          .json()
          .catch(() => null);

        if (
          response.ok &&
          payload?.ok &&
          payload.supported &&
          Array.isArray(payload.mins) &&
          Array.isArray(payload.maxs) &&
          payload.mins.length
        ) {
          waveform = {
            mins:payload.mins,
            maxs:payload.maxs,
            duration:Number(
              payload.duration ||
              stem.duration ||
              0
            ),
            sampleRate:Number(
              payload.sample_rate || 0
            ),
            channels:Number(
              payload.channels || 0
            )
          };
        }
      }

      if (!waveform) {
        waveform =
          await browserDecodeStemWaveform(
            stem
          );
      }

      if (waveform) {
        stem.waveformData = waveform;
      } else {
        stem.waveformError = true;
      }
    } catch (error) {
      stem.waveformError = true;
      console.warn(
        'Waveform extraction failed',
        stem.id,
        error
      );
    } finally {
      stem.waveformLoading = false;
      drawStemClipWaveforms(stem);
    }
  }

  async function runWaveformQueue() {
    if (waveformWorkerRunning) {
      return;
    }

    waveformWorkerRunning = true;

    try {
      while (waveformQueue.length) {
        const stem = waveformQueue.shift();

        if (!stem) {
          continue;
        }

        stem.waveformQueued = false;

        await loadStemWaveform(stem);

        await new Promise(resolve =>
          window.setTimeout(resolve,12)
        );
      }
    } finally {
      waveformWorkerRunning = false;
    }
  }

  function queueStemWaveform(stem) {
    if (
      !stem ||
      stem.waveformData ||
      stem.waveformLoading ||
      stem.waveformQueued
    ) {
      return;
    }

    stem.waveformQueued = true;
    waveformQueue.push(stem);
    runWaveformQueue().catch(() => {});
  }

  function drawStemClipWaveform(
    stem,
    clip,
    element
  ) {
    const canvas =
      element?.querySelector(
        '[data-stem-waveform-canvas]'
      );

    if (!canvas) {
      return;
    }

    const width = Math.max(
      1,
      Math.floor(canvas.clientWidth || 1)
    );
    const height = Math.max(
      1,
      Math.floor(canvas.clientHeight || 1)
    );
    const ratio = Math.max(
      1,
      Math.min(
        2,
        window.devicePixelRatio || 1
      )
    );

    const pixelWidth = Math.max(
      1,
      Math.floor(width * ratio)
    );
    const pixelHeight = Math.max(
      1,
      Math.floor(height * ratio)
    );

    if (
      canvas.width !== pixelWidth ||
      canvas.height !== pixelHeight
    ) {
      canvas.width = pixelWidth;
      canvas.height = pixelHeight;
    }

    const ctx = canvas.getContext('2d');

    if (!ctx) {
      return;
    }

    ctx.setTransform(
      ratio,
      0,
      0,
      ratio,
      0,
      0
    );
    ctx.clearRect(0,0,width,height);

    const waveform = stem.waveformData;

    if (
      !waveform ||
      !Array.isArray(waveform.mins) ||
      !waveform.mins.length
    ) {
      ctx.globalAlpha = .22;
      ctx.fillStyle = '#b8c8b0';
      ctx.fillRect(
        0,
        Math.floor(height / 2),
        width,
        1
      );
      ctx.globalAlpha = 1;
      return;
    }

    const count = waveform.mins.length;
    const sourceDuration = Math.max(
      .01,
      Number(
        waveform.duration ||
        stem.duration ||
        .01
      )
    );
    const startFraction = clamp(
      Number(clip.sourceStart || 0) /
        sourceDuration,
      0,
      1
    );
    const endFraction = clamp(
      Number(clip.sourceEnd || sourceDuration) /
        sourceDuration,
      startFraction,
      1
    );
    const startIndex = Math.floor(
      startFraction *
      Math.max(0,count - 1)
    );
    const endIndex = Math.max(
      startIndex + 1,
      Math.ceil(
        endFraction *
        Math.max(1,count - 1)
      )
    );
    const visiblePoints = Math.max(
      1,
      endIndex - startIndex
    );
    const mid = height / 2;
    const amplitude = Math.max(
      1,
      height * .45
    );

    ctx.fillStyle = '#b7cdb0';
    ctx.globalAlpha = .76;

    for (let x = 0; x < width; x++) {
      const fraction =
        width <= 1
          ? 0
          : x / (width - 1);
      const index = Math.min(
        count - 1,
        startIndex +
          Math.floor(
            fraction * visiblePoints
          )
      );
      const minValue = Math.max(
        -1,
        Math.min(
          1,
          Number(
            waveform.mins[index] || 0
          )
        )
      );
      const maxValue = Math.max(
        -1,
        Math.min(
          1,
          Number(
            waveform.maxs[index] || 0
          )
        )
      );
      const yTop =
        mid -
        maxValue * amplitude;
      const yBottom =
        mid -
        minValue * amplitude;

      ctx.fillRect(
        x,
        yTop,
        1,
        Math.max(
          1,
          yBottom - yTop
        )
      );
    }

    ctx.globalAlpha = 1;
  }

  function drawStemClipWaveforms(stem) {
    if (!stem?.mainClipLayer) {
      return;
    }

    (stem.clips || []).forEach(clip => {
      const element =
        stem.mainClipLayer.querySelector(
          `[data-main-clip-id="${clip.id}"]`
        );

      if (element) {
        drawStemClipWaveform(
          stem,
          clip,
          element
        );
      }
    });
  }

  function arrangementClipId(prefix = 'clip') {
    return `${prefix}-${Date.now()}-${Math.random()
      .toString(36)
      .slice(2,8)}`;
  }

  function timelineSignatureParts(
    signature = sessionTimeSignature
  ) {
    const match = String(signature)
      .match(/^(\d+)\s*\/\s*(\d+)$/);

    return {
      numerator:Math.max(
        1,
        Number(match?.[1] || 4)
      ),
      denominator:Math.max(
        1,
        Number(match?.[2] || 4)
      )
    };
  }

  function beatGuideSeconds() {
    const {
      denominator
    } = timelineSignatureParts();

    return Math.max(
      0.025,
      (
        60 /
        sourceTempo
      ) *
      (
        4 /
        denominator
      )
    );
  }

  function measureGuideSeconds() {
    const {
      numerator
    } = timelineSignatureParts();

    return Math.max(
      beatGuideSeconds(),
      beatGuideSeconds() *
        numerator
    );
  }

  function snapTimelineValue(value) {
    const clean = Number(value || 0);

    if (editSnapMode !== 'grid') {
      return clean;
    }

    const step = beatGuideSeconds();

    return Math.round(
      clean / step
    ) * step;
  }

  function setEditSnapMode(
    mode,
    persist = true
  ) {
    editSnapMode =
      mode === 'free'
        ? 'free'
        : 'grid';

    if (timelineSnapToggle) {
      const grid =
        editSnapMode === 'grid';

      timelineSnapToggle.textContent =
        grid
          ? 'SNAP: GRID'
          : 'FREE EDIT';

      timelineSnapToggle.classList.toggle(
        'active',
        grid
      );

      timelineSnapToggle.setAttribute(
        'aria-pressed',
        grid ? 'true' : 'false'
      );

      timelineSnapToggle.title =
        grid
          ? 'Stick edits to the beat guide. Click for free edit.'
          : 'Free edit is active. Click to stick edits to the beat guide.';
    }

    if (persist) {
      scheduleLocalSave(0);
    }
  }

  function clearArrangementSelection() {
    selectedArrangementClip = null;

    document.querySelectorAll(
      '.daw-editable-clip.selected,' +
      '.daw-library-loop-clip.selected'
    ).forEach(element => {
      element.classList.remove('selected');
    });

    updateTrackInspector();
  }

  function selectArrangementClip(
    kind,
    ownerId,
    clipId,
    element = null
  ) {
    clearArrangementSelection();

    selectedArrangementClip = {
      kind:String(kind || ''),
      ownerId:String(ownerId || ''),
      clipId:String(clipId || '')
    };

    element?.classList.add('selected');

    if (kind === 'stem') {
      markSelectedStem(
        Number(ownerId || 0)
      );
    }
  }

  function selectedArrangementData() {
    const selected =
      selectedArrangementClip;

    if (!selected) {
      return null;
    }

    if (selected.kind === 'stem') {
      const stem = stemById(
        Number(selected.ownerId || 0)
      );

      const clip =
        stem?.clips?.find(
          item =>
            item.id === selected.clipId
        );

      return (
        stem &&
        clip
      )
        ? {
            kind:'stem',
            stem,
            clip
          }
        : null;
    }

    if (selected.kind === 'library') {
      const clip =
        libraryClips.find(
          item =>
            item.id === selected.clipId
        );

      return clip
        ? {
            kind:'library',
            clip
          }
        : null;
    }

    return null;
  }

  function normalizeStemClips(
    stem,
    items
  ) {
    const sourceDuration = Math.max(
      0.05,
      Number(stem.duration || 0.05)
    );

    if (!Array.isArray(items)) {
      return [{
        id:`stem-${stem.id}-clip-1`,
        timelineStart:Math.max(
          0,
          Number(stem.offset || 0)
        ),
        timelineLength:
          sourceDuration *
          Math.max(
            0.01,
            Number(
              stem.timelineRatio || 1
            )
          ),
        sourceStart:0,
        sourceEnd:sourceDuration,
        gainDb:0,
        muted:false,
        fadeIn:0,
        fadeOut:0
      }];
    }

    return items
      .slice(0,64)
      .map((item,index) => {
        const sourceStart = clamp(
          item?.sourceStart ?? 0,
          0,
          sourceDuration
        );
        const sourceEnd = clamp(
          item?.sourceEnd ??
            sourceDuration,
          sourceStart,
          sourceDuration
        );
        const sourceLength = Math.max(
          0.05,
          sourceEnd -
            sourceStart
        );

        return {
          id:String(
            item?.id ||
            `stem-${stem.id}-clip-${index + 1}`
          ),
          timelineStart:clamp(
            item?.timelineStart ??
              stem.offset ??
              0,
            0,
            duration
          ),
          timelineLength:Math.max(
            0.05,
            Number(
              item?.timelineLength ??
              (
                sourceLength *
                Math.max(
                  0.01,
                  Number(
                    stem.timelineRatio || 1
                  )
                )
              )
            )
          ),
          sourceStart,
          sourceEnd,
          gainDb:clamp(
            item?.gainDb ?? 0,
            -24,
            12
          ),
          muted:Boolean(
            item?.muted
          ),
          fadeIn:Math.max(
            0,
            Number(
              item?.fadeIn || 0
            )
          ),
          fadeOut:Math.max(
            0,
            Number(
              item?.fadeOut || 0
            )
          )
        };
      })
      .filter(clip =>
        clip.sourceEnd -
          clip.sourceStart >
        0.01
      );
  }

  function stemActiveClipsAt(
    stem,
    globalTime
  ) {
    const time =
      Number(globalTime || 0);

    return (
      stem?.clips ||
      []
    )
      .filter(clip =>
        time >=
          Number(
            clip.timelineStart || 0
          ) &&
        time <
          Number(
            clip.timelineStart || 0
          ) +
          Number(
            clip.timelineLength || 0
          )
      )
      .sort(
        (a,b) =>
          a.timelineStart -
          b.timelineStart
      );
  }

  function clipEnvelopeGainAt(
    clip,
    globalTime
  ) {
    if (
      !clip ||
      clip.muted
    ) {
      return 0;
    }

    const start =
      Number(
        clip.timelineStart || 0
      );
    const length =
      Math.max(
        0.0001,
        Number(
          clip.timelineLength || 0
        )
      );
    const end =
      start + length;
    const time =
      Number(
        globalTime || 0
      );

    if (
      time < start ||
      time >= end
    ) {
      return 0;
    }

    const baseGain =
      Math.pow(
        10,
        Number(
          clip.gainDb || 0
        ) / 20
      );

    const local =
      Math.max(
        0,
        time - start
      );
    const remaining =
      Math.max(
        0,
        end - time
      );
    const fadeIn =
      Math.min(
        length,
        Math.max(
          0,
          Number(
            clip.fadeIn || 0
          )
        )
      );
    const fadeOut =
      Math.min(
        length,
        Math.max(
          0,
          Number(
            clip.fadeOut || 0
          )
        )
      );

    let envelope = 1;

    if (
      fadeIn > 0 &&
      local < fadeIn
    ) {
      envelope = Math.min(
        envelope,
        Math.sin(
          (
            local /
            fadeIn
          ) *
          Math.PI /
          2
        )
      );
    }

    if (
      fadeOut > 0 &&
      remaining < fadeOut
    ) {
      envelope = Math.min(
        envelope,
        Math.sin(
          (
            remaining /
            fadeOut
          ) *
          Math.PI /
          2
        )
      );
    }

    return Math.max(
      0,
      baseGain *
      envelope
    );
  }

  function autoCrossfadeStemClips(
    stem,
    selectedClip = null
  ) {
    if (!stem) {
      return false;
    }

    const clips =
      (stem.clips || [])
        .slice()
        .sort(
          (a,b) =>
            a.timelineStart -
            b.timelineStart
        );

    let changed = false;

    for (
      let index = 0;
      index < clips.length - 1;
      index++
    ) {
      const left =
        clips[index];
      const right =
        clips[index + 1];

      if (
        selectedClip &&
        left !== selectedClip &&
        right !== selectedClip
      ) {
        continue;
      }

      const leftEnd =
        left.timelineStart +
        left.timelineLength;
      const overlap =
        leftEnd -
        right.timelineStart;

      if (overlap > 0.01) {
        const clean =
          Math.min(
            overlap,
            left.timelineLength,
            right.timelineLength
          );

        left.fadeOut =
          Math.max(
            Number(
              left.fadeOut || 0
            ),
            clean
          );
        right.fadeIn =
          Math.max(
            Number(
              right.fadeIn || 0
            ),
            clean
          );

        changed = true;
      }
    }

    if (changed) {
      renderStemClips(stem);
      scheduleLocalSave(0);
      updateTrackInspector();
    }

    return changed;
  }

  function stemActiveClipAt(
    stem,
    globalTime
  ) {
    return (
      stemActiveClipsAt(
        stem,
        globalTime
      )[0] ||
      null
    );
  }

  function stemCrossfadeClipAt(
    stem,
    globalTime
  ) {
    return (
      stemActiveClipsAt(
        stem,
        globalTime
      )[1] ||
      null
    );
  }


  function updateStemClipDom(
    stem,
    clip
  ) {
    const element =
      stem.mainClipLayer
        ?.querySelector(
          `[data-main-clip-id="${clip.id}"]`
        );

    if (!element) return;

    const left = duration > 0
      ? (
          clip.timelineStart /
          duration
        ) * 100
      : 0;

    const width = duration > 0
      ? (
          clip.timelineLength /
          duration
        ) * 100
      : 0;

    element.style.left =
      `${Math.max(0,left)}%`;
    element.style.width =
      `${Math.max(.15,width)}%`;

    const time =
      element.querySelector(
        '[data-clip-time]'
      );

    if (time) {
      time.textContent =
        `${formatTime(
          clip.timelineStart
        )} · ${formatTime(
          clip.timelineLength
        )}`;
    }

    const length =
      Math.max(
        0.0001,
        Number(
          clip.timelineLength || 0
        )
      );

    clip.fadeIn =
      Math.min(
        length,
        Math.max(
          0,
          Number(
            clip.fadeIn || 0
          )
        )
      );
    clip.fadeOut =
      Math.min(
        length,
        Math.max(
          0,
          Number(
            clip.fadeOut || 0
          )
        )
      );

    const fadeInPercent =
      Math.min(
        100,
        (
          Math.max(
            0,
            Number(
              clip.fadeIn || 0
            )
          ) /
          length
        ) *
        100
      );

    const fadeOutPercent =
      Math.min(
        100,
        (
          Math.max(
            0,
            Number(
              clip.fadeOut || 0
            )
          ) /
          length
        ) *
        100
      );

    element.style.setProperty(
      '--clip-fade-in',
      `${fadeInPercent}%`
    );
    element.style.setProperty(
      '--clip-fade-out',
      `${fadeOutPercent}%`
    );
    element.style.setProperty(
      '--clip-gain-db',
      String(
        Number(
          clip.gainDb || 0
        )
      )
    );

    element.classList.toggle(
      'clip-muted',
      Boolean(clip.muted)
    );

    const gainReadout =
      element.querySelector(
        '[data-clip-gain]'
      );

    if (gainReadout) {
      const gainDb =
        Number(
          clip.gainDb || 0
        );

      gainReadout.textContent =
        gainDb === 0
          ? ''
          : `${gainDb >= 0 ? '+' : ''}${gainDb.toFixed(1)}dB`;
    }

    drawStemClipWaveform(
      stem,
      clip,
      element
    );

    updateTrackInspector();
  }

  function bindStemClipEditing(
    stem,
    clip,
    element
  ) {
    const body =
      element.querySelector(
        '[data-main-clip-body]'
      );
    const leftHandle =
      element.querySelector(
        '[data-main-clip-left]'
      );
    const rightHandle =
      element.querySelector(
        '[data-main-clip-right]'
      );
    const fadeInHandle =
      element.querySelector(
        '[data-main-fade-in]'
      );
    const fadeOutHandle =
      element.querySelector(
        '[data-main-fade-out]'
      );

    const select = event => {
      event?.stopPropagation?.();

      selectArrangementClip(
        'stem',
        stem.id,
        clip.id,
        element
      );
    };

    body?.addEventListener(
      'click',
      select
    );

    const bindDrag = (
      control,
      mode
    ) => {
      if (!control) return;

      let pointerId = null;
      let pointerStart = 0;
      let original = null;

      control.addEventListener(
        'pointerdown',
        event => {
          if (event.button !== 0) {
            return;
          }

          pointerId = event.pointerId;
          pointerStart =
            timelineTimeFromPointer(
              event
            );
          original = {
            timelineStart:
              clip.timelineStart,
            timelineLength:
              clip.timelineLength,
            sourceStart:
              clip.sourceStart,
            sourceEnd:
              clip.sourceEnd
          };

          select(event);

          control.setPointerCapture(
            pointerId
          );
          control.classList.add(
            'dragging'
          );

          event.preventDefault();
          event.stopPropagation();
        }
      );

      control.addEventListener(
        'pointermove',
        event => {
          if (
            event.pointerId !==
            pointerId ||
            !original
          ) {
            return;
          }

          const now =
            timelineTimeFromPointer(
              event
            );

          if (mode === 'move') {
            const raw =
              original.timelineStart +
              now -
              pointerStart;

            clip.timelineStart =
              clamp(
                snapTimelineValue(raw),
                0,
                Math.max(
                  0,
                  duration -
                    clip.timelineLength
                )
              );
          } else if (mode === 'left') {
            const originalEnd =
              original.timelineStart +
              original.timelineLength;

            let desiredStart =
              snapTimelineValue(now);

            desiredStart =
              clamp(
                desiredStart,
                0,
                originalEnd - .05
              );

            const timelineRatio =
              Math.max(
                .01,
                Number(
                  stem.timelineRatio || 1
                )
              );

            const requestedTimelineDelta =
              desiredStart -
              original.timelineStart;

            const requestedSourceDelta =
              requestedTimelineDelta /
              timelineRatio;

            const nextSourceStart =
              clamp(
                original.sourceStart +
                  requestedSourceDelta,
                0,
                original.sourceEnd - .05
              );

            const actualSourceDelta =
              nextSourceStart -
              original.sourceStart;

            const actualTimelineDelta =
              actualSourceDelta *
              timelineRatio;

            clip.sourceStart =
              nextSourceStart;
            clip.timelineStart =
              original.timelineStart +
              actualTimelineDelta;
            clip.timelineLength =
              original.timelineLength -
              actualTimelineDelta;
          } else if (mode === 'right') {
            const originalEnd =
              original.timelineStart +
              original.timelineLength;

            let desiredEnd =
              snapTimelineValue(now);

            desiredEnd =
              clamp(
                desiredEnd,
                original.timelineStart + .05,
                duration
              );

            const timelineRatio =
              Math.max(
                .01,
                Number(
                  stem.timelineRatio || 1
                )
              );

            const requestedTimelineDelta =
              desiredEnd -
              originalEnd;

            const requestedSourceDelta =
              requestedTimelineDelta /
              timelineRatio;

            const nextSourceEnd =
              clamp(
                original.sourceEnd +
                  requestedSourceDelta,
                original.sourceStart + .05,
                Number(stem.duration || 0.05)
              );

            const actualSourceDelta =
              nextSourceEnd -
              original.sourceEnd;

            const actualTimelineDelta =
              actualSourceDelta *
              timelineRatio;

            clip.sourceEnd =
              nextSourceEnd;
            clip.timelineLength =
              Math.max(
                .05,
                original.timelineLength +
                  actualTimelineDelta
              );
          }

          updateStemClipDom(
            stem,
            clip
          );
        }
      );

      const finish = event => {
        if (
          event.pointerId !==
          pointerId
        ) {
          return;
        }

        try {
          control.releasePointerCapture(
            pointerId
          );
        } catch (error) {}

        pointerId = null;
        original = null;

        control.classList.remove(
          'dragging'
        );

        stem.activeClipId = '';

        if (playing) {
          seekAllSafely(
            globalPosition(),
            true
          ).catch(() => {});
        }

        autoCrossfadeStemClips(
          stem,
          clip
        );

        scheduleLocalSave(0);
        updateTrackInspector();
      };

      control.addEventListener(
        'pointerup',
        finish
      );
      control.addEventListener(
        'pointercancel',
        finish
      );
    };

    const bindFade = (
      control,
      side
    ) => {
      if (!control) return;

      let pointerId = null;

      control.addEventListener(
        'pointerdown',
        event => {
          if (event.button !== 0) {
            return;
          }

          pointerId =
            event.pointerId;

          select(event);

          control.setPointerCapture(
            pointerId
          );

          control.classList.add(
            'dragging'
          );

          event.preventDefault();
          event.stopPropagation();
        }
      );

      control.addEventListener(
        'pointermove',
        event => {
          if (
            event.pointerId !==
            pointerId
          ) {
            return;
          }

          const now =
            timelineTimeFromPointer(
              event
            );
          const start =
            Number(
              clip.timelineStart || 0
            );
          const length =
            Math.max(
              .01,
              Number(
                clip.timelineLength || .01
              )
            );
          const end =
            start + length;

          if (side === 'in') {
            clip.fadeIn =
              clamp(
                now - start,
                0,
                length
              );
          } else {
            clip.fadeOut =
              clamp(
                end - now,
                0,
                length
              );
          }

          updateStemClipDom(
            stem,
            clip
          );

          event.preventDefault();
          event.stopPropagation();
        }
      );

      const finishFade =
        event => {
          if (
            event.pointerId !==
            pointerId
          ) {
            return;
          }

          try {
            control.releasePointerCapture(
              pointerId
            );
          } catch (error) {}

          pointerId = null;

          control.classList.remove(
            'dragging'
          );

          scheduleLocalSave(0);
          updateTrackInspector();
        };

      control.addEventListener(
        'pointerup',
        finishFade
      );
      control.addEventListener(
        'pointercancel',
        finishFade
      );

      control.addEventListener(
        'keydown',
        event => {
          const step =
            event.shiftKey
              ? .25
              : .05;
          const property =
            side === 'in'
              ? 'fadeIn'
              : 'fadeOut';
          const current =
            Number(
              clip[property] || 0
            );

          if (
            event.key ===
              'ArrowRight' ||
            event.key ===
              'ArrowUp'
          ) {
            event.preventDefault();

            clip[property] =
              clamp(
                current + step,
                0,
                clip.timelineLength
              );
          } else if (
            event.key ===
              'ArrowLeft' ||
            event.key ===
              'ArrowDown'
          ) {
            event.preventDefault();

            clip[property] =
              clamp(
                current - step,
                0,
                clip.timelineLength
              );
          } else if (
            event.key === 'Home' ||
            event.key === '0'
          ) {
            event.preventDefault();

            clip[property] = 0;
          } else {
            return;
          }

          updateStemClipDom(
            stem,
            clip
          );
          scheduleLocalSave(0);
        }
      );
    };

    bindFade(
      fadeInHandle,
      'in'
    );
    bindFade(
      fadeOutHandle,
      'out'
    );

    bindDrag(
      body,
      'move'
    );
    bindDrag(
      leftHandle,
      'left'
    );
    bindDrag(
      rightHandle,
      'right'
    );
  }

  function renderStemClips(stem) {
    if (!stem?.mainClipLayer) {
      return;
    }

    stem.mainClipLayer.innerHTML =
      (stem.clips || [])
        .map(clip => `
          <div
            class="daw-editable-clip daw-main-stem-clip"
            data-main-clip-id="${escapeHtml(clip.id)}"
          >
            <button
              type="button"
              class="daw-editable-clip-handle daw-editable-clip-left"
              data-main-clip-left
              aria-label="Trim or extend left edge"
              title="Drag to trim or extend"
            ></button>

            <button
              type="button"
              class="daw-editable-clip-body"
              data-main-clip-body
              title="Click to select · drag to move · Ctrl+S split · Ctrl+X delete"
            >
              <span class="daw-clip-fade daw-clip-fade-in" aria-hidden="true"></span>
              <span class="daw-clip-fade daw-clip-fade-out" aria-hidden="true"></span>

              <span
                class="daw-clip-fade-handle daw-clip-fade-in-handle"
                data-main-fade-in
                role="slider"
                tabindex="0"
                aria-label="Fade in"
                title="Drag to set fade in"
              ></span>

              <span
                class="daw-clip-fade-handle daw-clip-fade-out-handle"
                data-main-fade-out
                role="slider"
                tabindex="0"
                aria-label="Fade out"
                title="Drag to set fade out"
              ></span>

              <strong>${escapeHtml(
                stem.name ||
                stem.label ||
                `Stem ${stem.id}`
              )}</strong>
              <span data-clip-time></span>
              <em data-clip-gain></em>
              <canvas
                data-stem-waveform-canvas
                aria-hidden="true"
              ></canvas>
            </button>

            <button
              type="button"
              class="daw-editable-clip-handle daw-editable-clip-right"
              data-main-clip-right
              aria-label="Trim or extend right edge"
              title="Drag to trim or extend"
            ></button>
          </div>
        `)
        .join('');

    (stem.clips || [])
      .forEach(clip => {
        const element =
          stem.mainClipLayer
            .querySelector(
              `[data-main-clip-id="${clip.id}"]`
            );

        if (!element) return;

        bindStemClipEditing(
          stem,
          clip,
          element
        );
        updateStemClipDom(
          stem,
          clip
        );

        if (
          selectedArrangementClip?.kind ===
            'stem' &&
          String(
            selectedArrangementClip.ownerId
          ) === String(stem.id) &&
          selectedArrangementClip.clipId ===
            clip.id
        ) {
          element.classList.add(
            'selected'
          );
        }
      });

    queueStemWaveform(stem);
  }

  function selectClipAtPlayheadFallback() {
    const now =
      globalPosition();

    const stem =
      stemById(
        selectedStemId
      );

    if (stem) {
      const clip =
        stemActiveClipAt(
          stem,
          now
        );

      if (clip) {
        const element =
          stem.mainClipLayer
            ?.querySelector(
              `[data-main-clip-id="${clip.id}"]`
            );

        selectArrangementClip(
          'stem',
          stem.id,
          clip.id,
          element
        );

        return selectedArrangementData();
      }
    }

    const libraryClip =
      libraryClips.find(
        clip =>
          libraryClipIsActiveAt(
            clip,
            now
          )
      );

    if (libraryClip) {
      selectArrangementClip(
        'library',
        libraryClip.id,
        libraryClip.id,
        libraryClip.clipElement
      );

      return selectedArrangementData();
    }

    return null;
  }

  function splitSelectedSection() {
    let selected =
      selectedArrangementData() ||
      selectClipAtPlayheadFallback();

    if (!selected) {
      return false;
    }

    const splitTime =
      snapTimelineValue(
        globalPosition()
      );

    if (selected.kind === 'stem') {
      const {stem,clip} =
        selected;

      const start =
        clip.timelineStart;
      const end =
        clip.timelineStart +
        clip.timelineLength;

      if (
        splitTime <= start + .04 ||
        splitTime >= end - .04
      ) {
        return false;
      }

      const timelineRatio =
        Math.max(
          .01,
          Number(
            stem.timelineRatio || 1
          )
        );

      const delta =
        splitTime -
        start;

      const sourceSplit =
        clamp(
          clip.sourceStart +
            (
              delta /
              timelineRatio
            ),
          clip.sourceStart + .02,
          clip.sourceEnd - .02
        );

      const right = {
        id:arrangementClipId(
          `stem-${stem.id}`
        ),
        timelineStart:splitTime,
        timelineLength:
          end -
          splitTime,
        sourceStart:sourceSplit,
        sourceEnd:
          clip.sourceEnd,
        gainDb:Number(
          clip.gainDb || 0
        ),
        muted:Boolean(
          clip.muted
        ),
        fadeIn:0,
        fadeOut:Number(
          clip.fadeOut || 0
        )
      };

      clip.fadeOut = 0;

      clip.timelineLength =
        splitTime -
        start;
      clip.sourceEnd =
        sourceSplit;

      const index =
        stem.clips.indexOf(
          clip
        );

      stem.clips.splice(
        index + 1,
        0,
        right
      );

      stem.activeClipId = '';

      renderStemClips(stem);

      const element =
        stem.mainClipLayer
          ?.querySelector(
            `[data-main-clip-id="${right.id}"]`
          );

      selectArrangementClip(
        'stem',
        stem.id,
        right.id,
        element
      );

      scheduleLocalSave(0);
      return true;
    }

    const clip =
      selected.clip;

    const start =
      clip.timelineStart;
    const end =
      clip.timelineStart +
      clip.timelineLength;

    if (
      splitTime <= start + .04 ||
      splitTime >= end - .04
    ) {
      return false;
    }

    const sourceAtSplit =
      libraryClipSourceTimeAt(
        clip,
        splitTime
      );

    const oldSourceEnd =
      clip.sourceEnd;
    const oldTimelineEnd =
      end;

    clip.timelineLength =
      splitTime -
      start;
    clip.sourceEnd =
      Math.max(
        clip.sourceStart + .02,
        sourceAtSplit
      );

    updateLibraryClipDom(
      clip
    );

    const right =
      createLibraryClip({
        stemId:clip.stemId,
        name:clip.name,
        role:clip.role,
        song:clip.song,
        url:clip.url,
        sourceTempo:
          clip.sourceTempo,
        sourceSignature:
          clip.sourceSignature,
        sourceDuration:
          clip.sourceDuration,
        sourceStart:
          sourceAtSplit,
        sourceEnd:
          oldSourceEnd,
        timelineStart:
          splitTime,
        timelineLength:
          oldTimelineEnd -
          splitTime,
        baseFourBarLength:
          Math.max(
            .05,
            oldTimelineEnd -
              splitTime
          )
      },false);

    if (right) {
      selectArrangementClip(
        'library',
        right.id,
        right.id,
        right.clipElement
      );
    }

    scheduleLocalSave(0);
    return true;
  }

  function deleteSelectedSection() {
    const selected =
      selectedArrangementData() ||
      selectClipAtPlayheadFallback();

    if (!selected) {
      return false;
    }

    if (selected.kind === 'stem') {
      const {stem,clip} =
        selected;

      stem.clips =
        stem.clips.filter(
          item =>
            item !== clip
        );

      stem.activeClipId = '';
      clearArrangementSelection();
      renderStemClips(stem);

      if (!stem.audio.paused) {
        stem.audio.pause();
      }

      scheduleLocalSave(0);
      return true;
    }

    removeLibraryClip(
      selected.clip,
      true
    );
    clearArrangementSelection();
    return true;
  }

  function libraryClipId() {
    return `clip-${Date.now()}-${Math.random()
      .toString(36)
      .slice(2,8)}`;
  }

  function libraryClipSourceRatio(clip) {
    return sourceTempo /
      Math.max(
        40,
        Number(
          clip.sourceTempo ||
          sourceTempo
        )
      );
  }

  function libraryClipSourceTimeAt(
    clip,
    globalTime
  ) {
    const segmentLength = Math.max(
      0.05,
      clip.sourceEnd -
        clip.sourceStart
    );

    const timelineDelta = Math.max(
      0,
      globalTime -
        clip.timelineStart
    );

    const sourceDelta =
      timelineDelta *
      libraryClipSourceRatio(clip);

    return clip.sourceStart +
      (
        sourceDelta %
        segmentLength
      );
  }

  function libraryClipIsActiveAt(
    clip,
    globalTime
  ) {
    return (
      globalTime >= clip.timelineStart &&
      globalTime <
        clip.timelineStart +
        clip.timelineLength
    );
  }

  function ensureLibraryClipAudioGraph(clip) {
    if (
      !context ||
      !clip?.audio ||
      clip.sourceNode
    ) {
      return;
    }

    try {
      clip.sourceNode =
        context.createMediaElementSource(
          clip.audio
        );
      clip.gainNode =
        context.createGain();
      clip.gainNode.gain.value = 1;

      clip.sourceNode.connect(
        clip.gainNode
      );
      clip.gainNode.connect(
        busInput
      );
    } catch (error) {
      console.error(
        'Library clip audio graph failed',
        clip.id,
        error
      );
    }
  }

  function updateLibraryClipDom(clip) {
    if (!clip?.clipElement) {
      return;
    }

    const left = duration > 0
      ? (
          clip.timelineStart /
          duration
        ) * 100
      : 0;

    const width = duration > 0
      ? (
          clip.timelineLength /
          duration
        ) * 100
      : 0;

    const segmentPercent =
      clip.timelineLength > 0
        ? Math.min(
            100,
            (
              clip.baseFourBarLength /
              clip.timelineLength
            ) * 100
          )
        : 100;

    clip.clipElement.style.left =
      `${Math.max(0,left)}%`;
    clip.clipElement.style.width =
      `${Math.max(.2,width)}%`;
    clip.clipElement.style.setProperty(
      '--clip-segment-width',
      `${Math.max(1,segmentPercent)}%`
    );

    const barCount = Math.max(
      1,
      Math.round(
        clip.timelineLength /
        Math.max(
          .01,
          barsToTimelineSeconds(1)
        )
      )
    );

    const bars =
      clip.clipElement.querySelector(
        '[data-library-clip-bars]'
      );

    if (bars) {
      bars.textContent =
        `${barCount} bar${
          barCount === 1 ? '' : 's'
        }`;
    }
  }

  function removeLibraryClip(clip,persist = true) {
    if (!clip) return;

    clip.audio?.pause();

    try {
      clip.sourceNode?.disconnect();
      clip.gainNode?.disconnect();
    } catch (error) {}

    clip.leftRow?.remove();
    clip.arrangeRow?.remove();

    libraryClips =
      libraryClips.filter(
        item => item !== clip
      );

    if (persist) {
      scheduleLocalSave(0);
    }
  }

  function bindLibraryClipDrag(clip) {
    const body =
      clip.clipElement?.querySelector(
        '[data-library-clip-body]'
      );
    const leftHandle =
      clip.clipElement?.querySelector(
        '[data-library-clip-left]'
      );
    const rightHandle =
      clip.clipElement?.querySelector(
        '[data-library-clip-right]'
      );

    const select = event => {
      event?.stopPropagation?.();

      selectArrangementClip(
        'library',
        clip.id,
        clip.id,
        clip.clipElement
      );
    };

    body?.addEventListener(
      'click',
      select
    );

    const bind = (
      control,
      mode
    ) => {
      if (!control) return;

      let pointerId = null;
      let pointerStart = 0;
      let original = null;

      control.addEventListener(
        'pointerdown',
        event => {
          if (event.button !== 0) {
            return;
          }

          pointerId =
            event.pointerId;
          pointerStart =
            timelineTimeFromPointer(
              event
            );
          original = {
            timelineStart:
              clip.timelineStart,
            timelineLength:
              clip.timelineLength,
            sourceStart:
              clip.sourceStart,
            sourceEnd:
              clip.sourceEnd
          };

          select(event);

          control.setPointerCapture(
            pointerId
          );
          control.classList.add(
            'dragging'
          );

          event.preventDefault();
          event.stopPropagation();
        }
      );

      control.addEventListener(
        'pointermove',
        event => {
          if (
            event.pointerId !==
              pointerId ||
            !original
          ) {
            return;
          }

          const now =
            timelineTimeFromPointer(
              event
            );

          if (mode === 'move') {
            const raw =
              original.timelineStart +
              now -
              pointerStart;

            clip.timelineStart =
              clamp(
                snapTimelineValue(raw),
                0,
                Math.max(
                  0,
                  duration -
                    clip.timelineLength
                )
              );
          } else if (mode === 'left') {
            const originalEnd =
              original.timelineStart +
              original.timelineLength;

            let desiredStart =
              clamp(
                snapTimelineValue(now),
                0,
                originalEnd - .05
              );

            const timelineDelta =
              desiredStart -
              original.timelineStart;

            const sourceDelta =
              timelineDelta *
              libraryClipSourceRatio(
                clip
              );

            const nextSourceStart =
              clamp(
                original.sourceStart +
                  sourceDelta,
                0,
                original.sourceEnd - .02
              );

            const actualSourceDelta =
              nextSourceStart -
              original.sourceStart;

            const actualTimelineDelta =
              actualSourceDelta /
              Math.max(
                .0001,
                libraryClipSourceRatio(
                  clip
                )
              );

            clip.sourceStart =
              nextSourceStart;
            clip.timelineStart =
              original.timelineStart +
              actualTimelineDelta;
            clip.timelineLength =
              Math.max(
                .05,
                original.timelineLength -
                  actualTimelineDelta
              );
          } else {
            let desiredEnd =
              clamp(
                snapTimelineValue(now),
                clip.timelineStart + .05,
                duration
              );

            clip.timelineLength =
              Math.max(
                .05,
                desiredEnd -
                  clip.timelineStart
              );
          }

          updateLibraryClipDom(
            clip
          );
        }
      );

      const finish = event => {
        if (
          event.pointerId !==
          pointerId
        ) {
          return;
        }

        try {
          control.releasePointerCapture(
            pointerId
          );
        } catch (error) {}

        pointerId = null;
        original = null;
        control.classList.remove(
          'dragging'
        );

        syncLibraryClipPlayback(
          globalPosition(),
          true
        );
        scheduleLocalSave(0);
      };

      control.addEventListener(
        'pointerup',
        finish
      );
      control.addEventListener(
        'pointercancel',
        finish
      );
    };

    bind(
      body,
      'move'
    );
    bind(
      leftHandle,
      'left'
    );
    bind(
      rightHandle,
      'right'
    );
  }

  function renderLibraryClip(clip) {
    if (!trackList || !arrangeLanes) {
      return;
    }

    clip.leftRow?.remove();
    clip.arrangeRow?.remove();

    const leftRow =
      document.createElement('div');

    leftRow.className =
      'daw-track-row daw-library-clip-row';
    leftRow.dataset.libraryClipId =
      clip.id;
    leftRow.innerHTML = `
      <button
        class="daw-track-select daw-library-clip-select"
        type="button"
        title="${escapeHtml(clip.song)}"
      >
        <strong>${escapeHtml(clip.name)}</strong>
        <span>${escapeHtml(clip.role)} · ${escapeHtml(clip.song)}</span>
      </button>
      <div class="daw-library-clip-track-actions">
        <span>${Math.round(clip.sourceTempo)} BPM</span>
        <button
          type="button"
          data-remove-library-clip
          aria-label="Remove ${escapeHtml(clip.name)}"
        >×</button>
      </div>
    `;

    const arrangeRow =
      document.createElement('div');

    arrangeRow.className =
      'daw-arrange-row daw-library-clip-arrange-row';
    arrangeRow.dataset.libraryClipId =
      clip.id;
    arrangeRow.innerHTML = `
      <div class="daw-arrange-track">
        <div
          class="daw-editable-clip daw-library-loop-clip"
          data-library-loop-clip
        >
          <button
            type="button"
            class="daw-editable-clip-handle daw-editable-clip-left"
            data-library-clip-left
            aria-label="Trim or extend left edge"
            title="Drag to trim or extend"
          ></button>

          <button
            type="button"
            class="daw-editable-clip-body daw-library-loop-body"
            data-library-clip-body
            title="Click to select · drag to move · Ctrl+S split · Ctrl+X delete"
          >
            <strong>${escapeHtml(clip.name)}</strong>
            <span data-library-clip-bars>4 bars</span>
            <i aria-hidden="true"></i>
          </button>

          <button
            type="button"
            class="daw-editable-clip-handle daw-editable-clip-right"
            data-library-clip-right
            aria-label="Trim or extend right edge"
            title="Drag to extend"
          ></button>
        </div>
      </div>
    `;

    trackList.appendChild(leftRow);
    arrangeLanes.appendChild(arrangeRow);

    clip.leftRow = leftRow;
    clip.arrangeRow = arrangeRow;
    clip.clipElement =
      arrangeRow.querySelector(
        '[data-library-loop-clip]'
      );

    leftRow
      .querySelector(
        '[data-remove-library-clip]'
      )
      ?.addEventListener(
        'click',
        () => {
          if (
            selectedArrangementClip?.kind ===
              'library' &&
            selectedArrangementClip.clipId ===
              clip.id
          ) {
            clearArrangementSelection();
          }

          removeLibraryClip(clip);
        }
      );

    bindLibraryClipDrag(clip);
    updateLibraryClipDom(clip);

    if (
      selectedArrangementClip?.kind ===
        'library' &&
      selectedArrangementClip.clipId ===
        clip.id
    ) {
      clip.clipElement?.classList.add(
        'selected'
      );
    }
  }

  function createLibraryClip(
    data,
    persist = true
  ) {
    if (
      !data ||
      libraryClips.length >= 64
    ) {
      return null;
    }

    const sourceTempoValue =
      Math.max(
        40,
        Math.min(
          300,
          Number(
            data.sourceTempo ||
            sourceTempo
          )
        )
      );

    const sourceDuration =
      Math.max(
        .05,
        Number(
          data.sourceDuration ||
          data.duration ||
          0
        )
      );

    const sourceSegmentLength =
      timeSignatureQuarterBeats() *
      4 *
      60 /
      sourceTempoValue;

    let sourceStart = clamp(
      Number(data.sourceStart || 0),
      0,
      Math.max(
        0,
        sourceDuration - .02
      )
    );

    let sourceEnd = Number.isFinite(
      Number(data.sourceEnd)
    )
      ? clamp(
          Number(data.sourceEnd),
          sourceStart + .02,
          sourceDuration
        )
      : Math.min(
          sourceDuration,
          sourceStart +
            sourceSegmentLength
        );

    if (
      !Number.isFinite(
        Number(data.sourceEnd)
      ) &&
      sourceEnd - sourceStart <
        Math.min(
          sourceSegmentLength,
          sourceDuration
        ) -
        .02
    ) {
      sourceStart = Math.max(
        0,
        sourceDuration -
          sourceSegmentLength
      );
      sourceEnd = sourceDuration;
    }

    const defaultFourBarLength =
      Math.min(
        duration,
        Math.max(
          .1,
          barsToTimelineSeconds(4)
        )
      );

    const baseFourBarLength =
      Math.max(
        .05,
        Number(
          data.baseFourBarLength ||
          defaultFourBarLength
        )
      );

    const timelineStart = clamp(
      Number(
        data.timelineStart ??
        globalPosition()
      ),
      0,
      Math.max(
        0,
        duration - .05
      )
    );

    const maxTimelineLength =
      Math.max(
        .05,
        duration -
          timelineStart
      );

    const clip = {
      id:String(
        data.id ||
        libraryClipId()
      ),
      stemId:Number(data.stemId || 0),
      name:String(
        data.name || 'Library Stem'
      ),
      role:String(
        data.role || 'Other'
      ),
      song:String(
        data.song || 'Stonefellow'
      ),
      url:String(
        data.url ||
        `${stemMediaBase}${Number(data.stemId || 0)}`
      ),
      sourceTempo:sourceTempoValue,
      sourceSignature:String(
        data.sourceSignature || '4/4'
      ),
      sourceDuration,
      sourceStart,
      sourceEnd,
      timelineStart,
      timelineLength:clamp(
        Number(
          data.timelineLength ??
          baseFourBarLength
        ),
        .05,
        maxTimelineLength
      ),
      baseFourBarLength,
      audio:new Audio(),
      sourceNode:null,
      gainNode:null,
      pendingPlay:false,
      pendingSeek:false,
      active:false,
      leftRow:null,
      arrangeRow:null,
      clipElement:null
    };

    clip.audio.preload = 'auto';
    clip.audio.src = clip.url;

    setMediaTempo(
      clip.audio,
      sessionTempo /
        clip.sourceTempo
    );

    libraryClips.push(clip);

    renderLibraryClip(clip);

    if (context) {
      ensureLibraryClipAudioGraph(
        clip
      );
    }

    if (persist) {
      scheduleLocalSave(0);
    }

    return clip;
  }

  function restoreLibraryClips(items) {
    libraryClips
      .slice()
      .forEach(
        clip =>
          removeLibraryClip(
            clip,
            false
          )
      );

    (Array.isArray(items) ? items : [])
      .slice(0,64)
      .forEach(item => {
        createLibraryClip(
          item,
          false
        );
      });

    applySessionTempoToMedia();
  }

  async function seekLibraryClipSafely(
    clip,
    globalTime,
    serial
  ) {
    if (serial !== seekSerial) return;

    clip.pendingPlay = false;
    clip.pendingSeek = false;
    clip.active =
      libraryClipIsActiveAt(
        clip,
        globalTime
      );
    clip.audio.pause();

    if (
      !libraryClipIsActiveAt(
        clip,
        globalTime
      )
    ) {
      return;
    }

    await waitForMetadata(
      clip.audio
    );

    if (serial !== seekSerial) {
      return;
    }

    const target =
      libraryClipSourceTimeAt(
        clip,
        globalTime
      );

    await waitForSeek(
      clip.audio,
      Math.min(
        Math.max(
          clip.sourceStart,
          target
        ),
        Math.max(
          clip.sourceStart,
          clip.sourceEnd - .01
        )
      )
    );
  }

  function setLibraryClipPlayback(
    clip,
    globalTime,
    forceSeek = false
  ) {
    const active =
      libraryClipIsActiveAt(
        clip,
        globalTime
      );

    if (!active) {
      clip.active = false;

      if (!clip.audio.paused) {
        clip.audio.pause();
      }

      clip.pendingPlay = false;
      return;
    }

    const entering =
      !clip.active;

    clip.active = true;

    const target =
      libraryClipSourceTimeAt(
        clip,
        globalTime
      );

    const outsideSegment =
      clip.audio.currentTime <
        clip.sourceStart - .03 ||
      clip.audio.currentTime >=
        clip.sourceEnd - .025;

    if (
      (
        forceSeek ||
        entering ||
        outsideSegment
      ) &&
      !clip.pendingSeek
    ) {
      clip.pendingSeek = true;
      clip.pendingPlay = false;
      clip.audio.pause();

      waitForMetadata(
        clip.audio
      )
        .then(() =>
          waitForSeek(
            clip.audio,
            Math.min(
              Math.max(
                clip.sourceStart,
                target
              ),
              Math.max(
                clip.sourceStart,
                clip.sourceEnd - .01
              )
            )
          )
        )
        .then(() => {
          if (
            playing &&
            !seekInProgress &&
            clip.active
          ) {
            return clip.audio.play();
          }

          return null;
        })
        .catch(error => {
          console.error(
            'Library clip seek failed',
            clip.id,
            error
          );
        })
        .finally(() => {
          clip.pendingSeek = false;
        });

      return;
    }

    if (
      playing &&
      !seekInProgress &&
      !clip.pendingSeek &&
      clip.audio.paused &&
      !clip.pendingPlay
    ) {
      clip.pendingPlay = true;

      clip.audio.play()
        .catch(error => {
          console.error(
            'Library clip playback failed',
            clip.id,
            error
          );
        })
        .finally(() => {
          clip.pendingPlay = false;
        });
    }
  }

  function syncLibraryClipPlayback(
    globalTime = globalPosition(),
    forceSeek = false
  ) {
    libraryClips.forEach(clip => {
      setLibraryClipPlayback(
        clip,
        globalTime,
        forceSeek
      );
    });
  }

  function pauseLibraryClips() {
    libraryClips.forEach(clip => {
      clip.pendingPlay = false;
      clip.audio.pause();
    });
  }

  function stemLocalTimeForClip(
    stem,
    clip,
    globalTime
  ) {
    if (!clip) {
      return -1;
    }

    return (
      clip.sourceStart +
      (
        Number(globalTime || 0) -
        clip.timelineStart
      ) *
      (
        sourceTempo /
        Math.max(
          40,
          Number(
            stem.sourceTempo ||
            sourceTempo
          )
        )
      )
    );
  }

  function stemLocalTime(
    stem,
    globalTime
  ) {
    return stemLocalTimeForClip(
      stem,
      stemActiveClipAt(
        stem,
        globalTime
      ),
      globalTime
    );
  }


  function stemIsActiveAt(stem, globalTime) {
    return Boolean(
      stemActiveClipAt(
        stem,
        globalTime
      )
    );
  }

  function stemPlaybackSlotsAt(
    stem,
    globalTime
  ) {
    const active =
      stemActiveClipsAt(
        stem,
        globalTime
      );

    let main =
      active.find(
        clip =>
          clip.id ===
          stem.activeClipId
      ) || null;
    let crossfade =
      active.find(
        clip =>
          clip.id ===
          stem.activeCrossfadeClipId
      ) || null;

    active.forEach(clip => {
      if (
        clip === main ||
        clip === crossfade
      ) {
        return;
      }

      if (!main) {
        main = clip;
      } else if (!crossfade) {
        crossfade = clip;
      }
    });

    return {
      main,
      crossfade
    };
  }

  function setStemClipSlotPlayback(
    stem,
    audio,
    clip,
    slot,
    globalTime
  ) {
    if (!audio) {
      return;
    }

    const crossfade =
      slot === 'crossfade';
    const activeKey =
      crossfade
        ? 'activeCrossfadeClipId'
        : 'activeClipId';
    const seekKey =
      crossfade
        ? 'pendingCrossfadeSeek'
        : 'pendingBoundarySeek';
    const playKey =
      crossfade
        ? 'pendingCrossfadePlay'
        : 'pendingPlay';

    if (!clip) {
      stem[activeKey] = '';
      stem[playKey] = false;

      if (!audio.paused) {
        audio.pause();
      }

      return;
    }

    const localTime =
      stemLocalTimeForClip(
        stem,
        clip,
        globalTime
      );

    const changedClip =
      stem[activeKey] !==
      clip.id;

    if (
      changedClip &&
      !stem[seekKey]
    ) {
      stem[activeKey] =
        clip.id;
      stem[seekKey] =
        true;
      stem[playKey] =
        false;

      audio.pause();

      waitForMetadata(
        audio
      )
        .then(() =>
          waitForSeek(
            audio,
            Math.max(
              0,
              Math.min(
                Math.max(
                  0,
                  Number(stem.duration || 0) -
                    .01
                ),
                localTime
              )
            )
          )
        )
        .then(() => {
          if (
            playing &&
            !seekInProgress &&
            stem[activeKey] ===
              clip.id
          ) {
            return audio.play();
          }

          return null;
        })
        .catch(error => {
          console.error(
            crossfade
              ? 'Stem crossfade boundary seek failed'
              : 'Stem clip boundary seek failed',
            stem.id,
            error
          );
        })
        .finally(() => {
          stem[seekKey] =
            false;
        });

      return;
    }

    if (
      playing &&
      !seekInProgress &&
      !stem[seekKey] &&
      audio.paused &&
      !stem[playKey]
    ) {
      stem[playKey] =
        true;

      audio.play()
        .catch(error => {
          console.error(
            crossfade
              ? 'Stem crossfade playback failed'
              : 'Stem playback failed',
            stem.id,
            error
          );
        })
        .finally(() => {
          stem[playKey] =
            false;
        });
    }
  }

  function setStemPlayback(
    stem,
    globalTime
  ) {
    const slots =
      stemPlaybackSlotsAt(
        stem,
        globalTime
      );

    setStemClipSlotPlayback(
      stem,
      stem.audio,
      slots.main,
      'main',
      globalTime
    );

    setStemClipSlotPlayback(
      stem,
      stem.crossfadeAudio,
      slots.crossfade,
      'crossfade',
      globalTime
    );
  }


  function syncAll() {
    const now = globalPosition();
    stems.forEach(stem => setStemPlayback(stem, now));
  }

  function waitForMetadata(audio, timeoutMs = 1800) {
    if (audio.readyState >= 1) {
      return Promise.resolve();
    }

    return new Promise(resolve => {
      let done = false;

      const finish = () => {
        if (done) return;
        done = true;

        audio.removeEventListener(
          'loadedmetadata',
          finish
        );
        audio.removeEventListener(
          'durationchange',
          finish
        );
        audio.removeEventListener(
          'error',
          finish
        );

        resolve();
      };

      audio.addEventListener(
        'loadedmetadata',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'durationchange',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'error',
        finish,
        {once:true}
      );

      setTimeout(finish, timeoutMs);

      try {
        audio.load();
      } catch (error) {}
    });
  }

  function waitForSeek(audio, target, timeoutMs = 1800) {
    if (
      Number.isFinite(audio.currentTime) &&
      Math.abs(audio.currentTime - target) < 0.04 &&
      !audio.seeking
    ) {
      return Promise.resolve();
    }

    return new Promise(resolve => {
      let done = false;

      const finish = () => {
        if (done) return;
        done = true;

        audio.removeEventListener(
          'seeked',
          finish
        );
        audio.removeEventListener(
          'canplay',
          finish
        );
        audio.removeEventListener(
          'error',
          finish
        );

        resolve();
      };

      audio.addEventListener(
        'seeked',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'canplay',
        finish,
        {once:true}
      );
      audio.addEventListener(
        'error',
        finish,
        {once:true}
      );

      setTimeout(finish, timeoutMs);

      try {
        audio.currentTime = target;
      } catch (error) {
        finish();
      }
    });
  }

  async function seekStemClipAudioSafely(
    stem,
    audio,
    clip,
    globalTime,
    serial
  ) {
    if (
      serial !== seekSerial ||
      !audio ||
      !clip
    ) {
      return;
    }

    const localTime =
      stemLocalTimeForClip(
        stem,
        clip,
        globalTime
      );
    const stemDuration =
      Number(
        stem.duration || 0
      );

    if (!audio.paused) {
      audio.pause();
    }

    if (
      localTime < 0 ||
      localTime >= stemDuration
    ) {
      return;
    }

    await waitForMetadata(audio);

    if (serial !== seekSerial) {
      return;
    }

    const mediaDuration =
      Number.isFinite(
        audio.duration
      )
        ? audio.duration
        : stemDuration;

    const target = Math.max(
      0,
      Math.min(
        Math.max(
          0,
          mediaDuration - 0.01
        ),
        localTime
      )
    );

    await waitForSeek(
      audio,
      target
    );
  }

  async function seekStemSafely(
    stem,
    globalTime,
    serial
  ) {
    if (serial !== seekSerial) {
      return;
    }

    const active =
      stemActiveClipsAt(
        stem,
        globalTime
      );

    stem.activeClipId =
      active[0]?.id || '';
    stem.activeCrossfadeClipId =
      active[1]?.id || '';
    stem.pendingBoundarySeek =
      false;
    stem.pendingCrossfadeSeek =
      false;
    stem.pendingPlay =
      false;
    stem.pendingCrossfadePlay =
      false;

    if (!stem.audio.paused) {
      stem.audio.pause();
    }

    if (
      stem.crossfadeAudio &&
      !stem.crossfadeAudio.paused
    ) {
      stem.crossfadeAudio.pause();
    }

    await Promise.allSettled([
      seekStemClipAudioSafely(
        stem,
        stem.audio,
        active[0] || null,
        globalTime,
        serial
      ),
      seekStemClipAudioSafely(
        stem,
        stem.crossfadeAudio,
        active[1] || null,
        globalTime,
        serial
      )
    ]);
  }


  async function seekAllSafely(
    globalTime,
    resumeAfter = false
  ) {
    const serial = ++seekSerial;
    const target = Math.max(
      0,
      Math.min(
        duration,
        Number(globalTime || 0)
      )
    );

    seekInProgress = true;
    cancelAnimationFrame(frame);

    stems.forEach(stem => {
      stem.pendingPlay = false;
      stem.pendingCrossfadePlay = false;

      if (!stem.audio.paused) {
        stem.audio.pause();
      }

      if (
        stem.crossfadeAudio &&
        !stem.crossfadeAudio.paused
      ) {
        stem.crossfadeAudio.pause();
      }
    });

    position = target;

    if (currentTimeEl) {
      currentTimeEl.textContent =
        formatTime(position);
    }

    updatePlayhead(position);

    // Let the browser issue the required range requests together, but wait for
    // each decoder to confirm the new position before restarting playback.
    await Promise.allSettled([
      ...stems.map(stem =>
        seekStemSafely(
          stem,
          position,
          serial
        )
      ),
      ...libraryClips.map(clip =>
        seekLibraryClipSafely(
          clip,
          position,
          serial
        )
      )
    ]);

    if (serial !== seekSerial) {
      return;
    }

    seekInProgress = false;

    if (resumeAfter) {
      startedAt = performance.now();
      playing = true;

      stems.forEach(stem => {
        setStemPlayback(
          stem,
          position
        );
      });

      syncLibraryClipPlayback(
        position,
        false
      );

      if (playButton) {
        playButton.textContent = '❚❚';
        playButton.setAttribute(
          'aria-label',
          'Pause'
        );
      }

      frame = requestAnimationFrame(render);
    } else {
      playing = false;

      if (playButton) {
        playButton.textContent = '▶';
        playButton.setAttribute(
          'aria-label',
          'Play'
        );
      }
    }
  }

  function updatePlayhead(now) {
    const percent = duration > 0
      ? Math.max(
          0,
          Math.min(
            100,
            (now / duration) * 100
          )
        )
      : 0;

    if (playhead) {
      playhead.style.left = `${percent}%`;
    }
  }

  function seekTo(time) {
    if (
      recordingActive ||
      recordingCountInActive ||
      recordingStopping ||
      recordingPendingSave
    ) {
      return Promise.resolve(
        false
      );
    }

    const resumeAfter = playing;

    // Invalidate the running clock immediately so it cannot advance while the
    // media elements are repositioning.
    playing = false;

    return seekAllSafely(
      time,
      resumeAfter
    );
  }

  function render() {
    if (seekInProgress) {
      return;
    }

    let now = globalPosition();

    if (
      playing &&
      !recordingActive &&
      loopActive &&
      loopEnd > loopStart &&
      now >= loopEnd
    ) {
      const resumeAfter = true;
      playing = false;

      seekAllSafely(
        loopStart,
        resumeAfter
      ).catch(error => {
        console.error(
          'Loop seek failed',
          error
        );
      });

      return;
    }

    if (currentTimeEl) {
      currentTimeEl.textContent =
        formatTime(now);
    }

    updatePlayhead(now);
    applyAutomationAt(now);
    syncLibraryClipPlayback(
      now,
      false
    );
    updateEqDisplays(false);
    updateGroupMeters(false);
    updateMasterMeter(false);

    if (!playing) {
      return;
    }

    syncMetronomeAt(now);

    if (
      now >= duration &&
      !recordingActive
    ) {
      pauseAll();
      position = duration;

      if (currentTimeEl) {
        currentTimeEl.textContent =
          formatTime(duration);
      }

      updatePlayhead(duration);
      return;
    }

    syncAll();
    frame = requestAnimationFrame(render);
  }

  async function playAll() {
    ensureAudioGraph();

    if (!context) {
      alert(
        'Web Audio is not available in this browser.'
      );
      return;
    }

    if (context.state === 'suspended') {
      await context.resume();
    }

    metronomeLastBeat = -1;

    if (
      loopActive &&
      loopEnd > loopStart &&
      (
        position < loopStart ||
        position >= loopEnd
      )
    ) {
      position = loopStart;
    } else if (
      position >= duration - 0.02 &&
      !recordingActive
    ) {
      position =
        loopActive &&
        loopEnd > loopStart
          ? loopStart
          : 0;
    }

    // Start from one confirmed decoder position rather than asking every
    // animation frame to correct currentTime.
    playing = false;

    await seekAllSafely(
      position,
      true
    );
  }

  function pauseAll() {
    if (playing) {
      position = globalPosition();
    }

    ++seekSerial;
    seekInProgress = false;
    playing = false;
    metronomeLastBeat = -1;

    stems.forEach(stem => {
      stem.audio.pause();
      stem.crossfadeAudio?.pause();
    });

    pauseLibraryClips();

    cancelAnimationFrame(frame);
    restoreStaticAutomationTargets();
    updateEqDisplays(true);
    updateGroupMeters(true);
    updateMasterMeter(true);

    if (playButton) {
      playButton.textContent = '▶';
      playButton.setAttribute('aria-label', 'Play');
    }

    updatePlayhead(position);
  }

  function selectStem(stem) {
    markSelectedStem(stem.id);

    stem.mixer?.scrollIntoView({
      block: 'nearest',
      inline: 'center',
      behavior: 'smooth'
    });

    scheduleLocalSave();
  }

  function applyPreset(name) {
    stems.forEach(stem => {
      const role = String(stem.role || '').toLowerCase();

      stem.solo = false;
      stem.muted = false;

      if (name === 'vocals') {
        stem.solo = role === 'vocal';
      } else if (name === 'instrumental') {
        stem.muted = role === 'vocal';
      } else if (name === 'rhythm') {
        stem.solo = ['drums', 'percussion', 'bass'].includes(role);
      }
    });

    updateGains();
  }

  function setPluginState(name, enabled) {
    if (!(name in pluginState)) return;

    pluginState[name] = Boolean(enabled);

    document.querySelectorAll(
      `[data-master-plugin="${name}"]`
    ).forEach(button => {
      button.classList.toggle('active', pluginState[name]);
    });

    if (context) {
      rebuildMasterGraph();
    }

    scheduleLocalSave();
  }

  function defaultGroupForRole(role) {
    const value = String(role || '').toLowerCase();

    if (value === 'vocal') {
      return 'vocals';
    }

    if (
      ['drums','percussion','bass']
        .includes(value)
    ) {
      return 'rhythm';
    }

    return 'music';
  }

  function resetMix() {
    stems.forEach(stem => {
      stem.muted = false;
      stem.solo = false;
      stem.userGain = stem.initialGain;

      if (stem.volume) {
        stem.volume.value = String(
          stem.userGain
        );
      }

      setStemPan(
        stem,
        stem.initialPan
      );
      setStemSend(stem,'a',0,false);
      setStemSend(stem,'b',0,false);
      setTrackTrim(stem,0,false);
    });

    if (masterVolume) {
      masterVolume.value = '1';
    }

    if (masterValue) {
      masterValue.textContent = '0.0 dB';
    }

    if (masterGain) {
      masterGain.gain.value = 1;
    }

    setReturnLevel('a',0.8,false);
    setReturnLevel('b',0.7,false);
    setSessionTempo(sourceTempo,false);

    Object.keys(groupState).forEach(group => {
      groupState[group].volume = 1;
      groupState[group].muted = false;
      updateGroupBus(group,false);
    });

    setPluginState('eq',true);
    setPluginState('compressor',true);
    setPluginState('reverb',false);

    clearLoop();
    updateGains();
    scheduleLocalSave(0);
  }

  // ---------------------------------------------------------
  // Track automation lanes.
  // ---------------------------------------------------------
  function automationSpec(parameter) {
    if (parameter === 'pan') {
      return {
        min:-1,
        max:1,
        fallback:0,
        format:value => panText(value)
      };
    }

    if (parameter === 'volume') {
      return {
        min:0,
        max:1.5,
        fallback:1,
        format:value => dbText(value)
      };
    }

    return {
      min:0,
      max:1,
      fallback:0,
      format:value => `${Math.round(value * 100)}%`
    };
  }

  function automationValueAt(points,time,fallback) {
    if (!Array.isArray(points) || !points.length) {
      return fallback;
    }

    if (time <= points[0].t) {
      return points[0].v;
    }

    const last = points[points.length - 1];

    if (time >= last.t) {
      return last.v;
    }

    for (let i = 1; i < points.length; i++) {
      const right = points[i];

      if (time <= right.t) {
        const left = points[i - 1];
        const span = Math.max(
          0.0001,
          right.t - left.t
        );
        const ratio = (time - left.t) / span;

        return left.v +
          (right.v - left.v) * ratio;
      }
    }

    return fallback;
  }

  function automationPointXY(parameter,point) {
    const spec = automationSpec(parameter);
    const x = duration > 0
      ? clamp(point.t / duration,0,1) * 1000
      : 0;
    const normalized = (
      point.v - spec.min
    ) / Math.max(0.0001,spec.max - spec.min);
    const y = 78 - clamp(normalized,0,1) * 68;

    return {x,y};
  }

  function automationXYValue(parameter,x,y) {
    const spec = automationSpec(parameter);
    const t = clamp(x / 1000,0,1) * duration;
    const normalized = clamp(
      (78 - y) / 68,
      0,
      1
    );
    const v = spec.min +
      normalized * (spec.max - spec.min);

    return {
      t:Math.round(t * 1000) / 1000,
      v:Math.round(v * 1000) / 1000
    };
  }

  function renderAutomationLane(stem) {
    if (
      !stem?.automationGraph ||
      !stem.automationPath ||
      !stem.automationPointsGroup
    ) {
      return;
    }

    const parameter =
      stem.automationParameter?.value ||
      'volume';
    const points = stem.automation[parameter] || [];
    const spec = automationSpec(parameter);

    const path = points
      .map((point,index) => {
        const {x,y} = automationPointXY(
          parameter,
          point
        );
        return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
      })
      .join(' ');

    stem.automationPath.setAttribute(
      'd',
      path
    );

    stem.automationPointsGroup.innerHTML =
      points.map((point,index) => {
        const {x,y} = automationPointXY(
          parameter,
          point
        );

        const selected =
          point === stem.selectedAutomationPoint;

        return `
          <g
            class="daw-automation-point${selected ? ' selected' : ''}"
            data-automation-point="${index}"
            transform="translate(${x} ${y})"
            tabindex="0"
            role="slider"
            aria-valuetext="${formatTime(point.t)} · ${spec.format(point.v)}"
          >
            <circle r="6"></circle>
            <text x="8" y="-8">${spec.format(point.v)}</text>
          </g>
        `;
      }).join('');

    if (stem.automationDelete) {
      stem.automationDelete.disabled =
        !stem.selectedAutomationPoint ||
        !points.includes(
          stem.selectedAutomationPoint
        );
    }

    bindAutomationPoints(stem);
  }

  function setAutomationOpen(stem,open,persist = true) {
    if (!stem) return;

    stem.automationOpen = Boolean(open);

    stem.leftRow?.classList.toggle(
      'automation-open',
      stem.automationOpen
    );
    stem.arrangeRow?.classList.toggle(
      'automation-open',
      stem.automationOpen
    );
    stem.automationToggle?.classList.toggle(
      'active',
      stem.automationOpen
    );

    if (stem.automationLane) {
      stem.automationLane.hidden =
        !stem.automationOpen;
    }

    if (stem.automationOpen) {
      renderAutomationLane(stem);
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function automationGraphPoint(event,svg) {
    const point = svgPoint(event,svg);

    return {
      x:clamp(point.x,0,1000),
      y:clamp(point.y,10,78)
    };
  }

  function updateAutomationGraphDom(stem) {
    if (
      !stem?.automationGraph ||
      !stem.automationPath ||
      !stem.automationPointsGroup
    ) {
      return;
    }

    const parameter =
      stem.automationParameter?.value ||
      'volume';
    const points = stem.automation[parameter] || [];
    const spec = automationSpec(parameter);

    stem.automationPath.setAttribute(
      'd',
      points.map((point,index) => {
        const {x,y} = automationPointXY(
          parameter,
          point
        );
        return `${index === 0 ? 'M' : 'L'} ${x} ${y}`;
      }).join(' ')
    );

    stem.automationPointsGroup
      .querySelectorAll('[data-automation-point]')
      .forEach((node,index) => {
        const point = points[index];
        if (!point) return;

        const {x,y} = automationPointXY(
          parameter,
          point
        );

        node.setAttribute(
          'transform',
          `translate(${x} ${y})`
        );
        node.setAttribute(
          'aria-valuetext',
          `${formatTime(point.t)} · ${spec.format(point.v)}`
        );

        const text = node.querySelector('text');
        if (text) {
          text.textContent = spec.format(point.v);
        }
      });
  }

  function bindAutomationPoints(stem) {
    const parameter =
      stem.automationParameter?.value ||
      'volume';
    const points = stem.automation[parameter] || [];

    stem.automationPointsGroup
      ?.querySelectorAll('[data-automation-point]')
      .forEach(node => {
        const index = Number(
          node.dataset.automationPoint
        );
        let pointerId = null;

        node.addEventListener('pointerdown',event => {
          if (event.button !== 0) return;

          if (event.altKey) {
            event.preventDefault();
            event.stopPropagation();

            const point =
              points[index];

            if (
              stem.selectedAutomationPoint ===
              point
            ) {
              stem.selectedAutomationPoint = null;
            }

            points.splice(index,1);
            renderAutomationLane(stem);
            scheduleLocalSave(0);
            return;
          }

          stem.selectedAutomationPoint =
            points[index] || null;

          stem.automationPointsGroup
            ?.querySelectorAll(
              '[data-automation-point]'
            )
            .forEach(pointNode => {
              pointNode.classList.toggle(
                'selected',
                pointNode === node
              );
            });

          if (stem.automationDelete) {
            stem.automationDelete.disabled =
              !stem.selectedAutomationPoint;
          }

          pointerId = event.pointerId;
          node.setPointerCapture(pointerId);
          node.classList.add('dragging');
          event.preventDefault();
          event.stopPropagation();
        });

        node.addEventListener('pointermove',event => {
          if (event.pointerId !== pointerId) {
            return;
          }

          const point = automationGraphPoint(
            event,
            stem.automationGraph
          );
          const next = automationXYValue(
            parameter,
            point.x,
            point.y
          );

          if (points[index]) {
            points[index].t = next.t;
            points[index].v = next.v;
          }

          updateAutomationGraphDom(stem);
          scheduleLocalSave();
        });

        const finish = event => {
          if (event.pointerId !== pointerId) {
            return;
          }

          try {
            node.releasePointerCapture(pointerId);
          } catch (error) {}

          pointerId = null;
          node.classList.remove('dragging');

          points.sort((a,b) => a.t - b.t);
          renderAutomationLane(stem);
          scheduleLocalSave();
        };

        node.addEventListener('pointerup',finish);
        node.addEventListener('pointercancel',finish);

        node.addEventListener('dblclick',event => {
          event.preventDefault();
          event.stopPropagation();

          if (
            stem.selectedAutomationPoint ===
            points[index]
          ) {
            stem.selectedAutomationPoint = null;
          }

          points.splice(index,1);
          renderAutomationLane(stem);
          scheduleLocalSave();
        });

        node.addEventListener('keydown',event => {
          if (
            event.key !== 'Delete' &&
            event.key !== 'Backspace'
          ) {
            return;
          }

          event.preventDefault();

          if (
            stem.selectedAutomationPoint ===
            points[index]
          ) {
            stem.selectedAutomationPoint = null;
          }

          points.splice(index,1);
          renderAutomationLane(stem);
          scheduleLocalSave();
        });
      });
  }

  function bindAutomationEditor(stem) {
    stem.automationToggle?.addEventListener(
      'click',
      event => {
        event.stopPropagation();
        setAutomationOpen(
          stem,
          !stem.automationOpen
        );
      }
    );

    stem.automationParameter?.addEventListener(
      'change',
      () => {
        stem.selectedAutomationPoint = null;
        renderAutomationLane(stem);
        scheduleLocalSave();
      }
    );

    stem.automationDelete?.addEventListener(
      'click',
      () => {
        const parameter =
          stem.automationParameter?.value ||
          'volume';
        const points =
          stem.automation[parameter] || [];

        const index = points.indexOf(
          stem.selectedAutomationPoint
        );

        if (index < 0) {
          stem.selectedAutomationPoint = null;
          renderAutomationLane(stem);
          return;
        }

        points.splice(index,1);
        stem.selectedAutomationPoint = null;
        renderAutomationLane(stem);
        scheduleLocalSave(0);
      }
    );

    stem.automationClear?.addEventListener(
      'click',
      () => {
        const parameter =
          stem.automationParameter?.value ||
          'volume';
        const points =
          stem.automation[parameter] || [];

        if (!points.length) {
          return;
        }

        if (
          !window.confirm(
            `Clear ${parameter} automation for this track?`
          )
        ) {
          return;
        }

        stem.automation[parameter] = [];
        stem.selectedAutomationPoint = null;
        renderAutomationLane(stem);
        restoreStaticAutomationTargets();
        scheduleLocalSave(0);
      }
    );

    stem.automationClearAll?.addEventListener(
      'click',
      () => {
        const hasAutomation =
          Object.values(stem.automation)
            .some(
              points =>
                Array.isArray(points) &&
                points.length
            );

        if (!hasAutomation) {
          return;
        }

        if (
          !window.confirm(
            'Clear all automation for this track?'
          )
        ) {
          return;
        }

        stem.automation = {
          volume:[],
          pan:[],
          auxA:[],
          auxB:[]
        };
        stem.selectedAutomationPoint = null;
        renderAutomationLane(stem);
        restoreStaticAutomationTargets();
        scheduleLocalSave(0);
      }
    );

    stem.automationGraph?.addEventListener(
      'click',
      event => {
        if (
          event.target.closest(
            '[data-automation-point]'
          )
        ) {
          return;
        }

        const parameter =
          stem.automationParameter?.value ||
          'volume';
        const point = automationGraphPoint(
          event,
          stem.automationGraph
        );

        const addedPoint =
          automationXYValue(
            parameter,
            point.x,
            point.y
          );

        stem.automation[parameter].push(
          addedPoint
        );
        stem.automation[parameter].sort(
          (a,b) => a.t - b.t
        );

        stem.selectedAutomationPoint =
          addedPoint;

        renderAutomationLane(stem);
        scheduleLocalSave();
      }
    );
  }

  function applyAutomationAt(time) {
    const anySolo = stems.some(
      stem => stem.solo
    );

    stems.forEach(stem => {
      const volumePoints =
        stem.automation.volume || [];
      const panPoints =
        stem.automation.pan || [];
      const auxAPoints =
        stem.automation.auxA || [];
      const auxBPoints =
        stem.automation.auxB || [];

      const volume = automationValueAt(
        volumePoints,
        time,
        stem.userGain
      );

      const pan = automationValueAt(
        panPoints,
        time,
        Number(stem.pan?.value || 0)
      );

      const sendA = automationValueAt(
        auxAPoints,
        time,
        stem.sends.a
      );

      const sendB = automationValueAt(
        auxBPoints,
        time,
        stem.sends.b
      );

      const audible =
        !stem.muted &&
        (!anySolo || stem.solo);

      const playbackSlots =
        stemPlaybackSlotsAt(
          stem,
          time
        );

      const mainClipGain =
        playbackSlots.main
          ? clipEnvelopeGainAt(
              playbackSlots.main,
              time
            )
          : 0;

      const crossfadeClipGain =
        playbackSlots.crossfade
          ? clipEnvelopeGainAt(
              playbackSlots.crossfade,
              time
            )
          : 0;

      if (
        stem.clipGainNode &&
        context
      ) {
        stem.clipGainNode.gain
          .setTargetAtTime(
            mainClipGain,
            context.currentTime,
            0.006
          );
      }

      if (
        stem.crossfadeGainNode &&
        context
      ) {
        stem.crossfadeGainNode.gain
          .setTargetAtTime(
            crossfadeClipGain,
            context.currentTime,
            0.006
          );
      }

      if (stem.gainNode && context) {
        stem.gainNode.gain.setTargetAtTime(
          audible
            ? volume
            : 0,
          context.currentTime,
          0.008
        );
      }

      if (stem.panNode && context) {
        stem.panNode.pan.setTargetAtTime(
          pan,
          context.currentTime,
          0.008
        );
      }

      applyStemSendAudio(stem,'a',sendA);
      applyStemSendAudio(stem,'b',sendB);
    });
  }

  function restoreStaticAutomationTargets() {
    stems.forEach(stem => {
      if (stem.panNode && context) {
        stem.panNode.pan.setTargetAtTime(
          Number(stem.pan?.value || 0),
          context.currentTime,
          0.008
        );
      }

      applyStemSendAudio(
        stem,
        'a',
        stem.sends.a
      );
      applyStemSendAudio(
        stem,
        'b',
        stem.sends.b
      );
    });

    updateGains();
  }

  // ---------------------------------------------------------
  // Main arrange timeline: scroll, seek, highlight, repeat.
  // ---------------------------------------------------------
  function setTimelineZoom(value,persist = true,anchorTime = null) {
    const previousWidth =
      timelineSurface?.getBoundingClientRect().width ||
      1;

    const viewportWidth =
      dawArrange?.clientWidth ||
      1;

    const anchor = anchorTime === null
      ? (
          duration > 0
            ? (
                (
                  (dawArrange?.scrollLeft || 0) +
                  viewportWidth / 2
                ) / previousWidth
              ) * duration
            : 0
        )
      : clamp(anchorTime,0,duration);

    timelineZoom = clamp(value,0.2,12);

    resizeTimelineSurface();

    if (
      dawArrange &&
      timelineSurface &&
      duration > 0
    ) {
      const width =
        timelineSurface.getBoundingClientRect().width;
      const targetX =
        (anchor / duration) * width;

      dawArrange.scrollLeft = Math.max(
        0,
        targetX - viewportWidth / 2
      );
    }

    if (timelineZoomValue) {
      timelineZoomValue.textContent =
        `${Math.round(timelineZoom * 100)}%`;
    }

    if (persist) {
      scheduleLocalSave();
    }
  }

  function resizeTimelineSurface() {
    if (!timelineSurface || !dawArrange) return;

    const viewport = Math.max(
      1,
      dawArrange.clientWidth
    );
    const pixelsPerSecond =
      duration > 240 ? 10 : 22;

    const target = Math.max(
      viewport,
      duration *
        pixelsPerSecond *
        timelineZoom
    );

    timelineSurface.style.width =
      `${Math.ceil(target)}px`;

    const beatGuidePixels =
      duration > 0
        ? (
            beatGuideSeconds() /
            duration
          ) * target
        : 0;

    const measureGuidePixels =
      duration > 0
        ? (
            measureGuideSeconds() /
            duration
          ) * target
        : 0;

    const showBeats =
      beatGuidePixels >= 9;
    const showSubdivisions =
      beatGuidePixels >= 34;

    timelineSurface.style.setProperty(
      '--measure-guide-px',
      `${Math.max(2,measureGuidePixels)}px`
    );

    timelineSurface.style.setProperty(
      '--beat-guide-px',
      showBeats
        ? `${Math.max(2,beatGuidePixels)}px`
        : '999999px'
    );

    timelineSurface.style.setProperty(
      '--sub-guide-px',
      showSubdivisions
        ? `${Math.max(2,beatGuidePixels / 2)}px`
        : '999999px'
    );

    timelineSurface.dataset.rulerDensity =
      showSubdivisions
        ? 'fine'
        : (
            showBeats
              ? 'beats'
              : 'measures'
          );

    if (timelineZoomValue) {
      timelineZoomValue.textContent =
        `${Math.round(timelineZoom * 100)}%`;
    }

    renderRuler();
    renderMarkersAndRegions();

    stems.forEach(stem => {
      drawStemClipWaveforms(stem);
    });
  }

  function renderRuler() {
    if (
      !rulerLines ||
      !timelineSurface ||
      duration <= 0
    ) {
      return;
    }

    const width = Math.max(
      1,
      timelineSurface.getBoundingClientRect().width
    );
    const beatSeconds =
      beatGuideSeconds();
    const measureSeconds =
      measureGuideSeconds();
    const {
      numerator
    } = timelineSignatureParts();
    const beatPixels =
      (beatSeconds / duration) * width;
    const measurePixels =
      (measureSeconds / duration) * width;

    const showBeatTicks =
      beatPixels >= 10;
    const showBeatLabels =
      beatPixels >= 44;
    const showHalfBeatTicks =
      beatPixels >= 70;
    const measureLabelEvery = Math.max(
      1,
      Math.ceil(
        38 /
        Math.max(1,measurePixels)
      )
    );
    const totalMeasures = Math.max(
      1,
      Math.ceil(
        duration /
        measureSeconds
      )
    );
    const markers = [];

    for (
      let measureIndex = 0;
      measureIndex <= totalMeasures;
      measureIndex++
    ) {
      const measureTime =
        measureIndex *
        measureSeconds;

      if (measureTime > duration + .001) {
        break;
      }

      const left =
        (measureTime / duration) * 100;
      const measureNumber =
        measureIndex + 1;
      const showMeasureLabel =
        measureIndex % measureLabelEvery === 0;

      markers.push(`
        <span
          class="daw-ruler-marker daw-ruler-measure"
          style="left:${left}%"
        >
          ${showMeasureLabel
            ? `<b>M${measureNumber}</b><small>${formatTime(measureTime)}</small>`
            : ''}
        </span>
      `);

      if (!showBeatTicks) {
        continue;
      }

      for (
        let beatIndex = 1;
        beatIndex < numerator;
        beatIndex++
      ) {
        const beatTime =
          measureTime +
          beatIndex * beatSeconds;

        if (beatTime > duration) {
          break;
        }

        const beatLeft =
          (beatTime / duration) * 100;

        markers.push(`
          <span
            class="daw-ruler-marker daw-ruler-beat"
            style="left:${beatLeft}%"
          >
            ${showBeatLabels
              ? `<b>${measureNumber}.${beatIndex + 1}</b>`
              : ''}
          </span>
        `);
      }

      if (showHalfBeatTicks) {
        for (
          let beatIndex = 0;
          beatIndex < numerator;
          beatIndex++
        ) {
          const halfBeatTime =
            measureTime +
            (beatIndex + .5) *
            beatSeconds;

          if (halfBeatTime > duration) {
            break;
          }

          const halfLeft =
            (halfBeatTime / duration) * 100;

          markers.push(`
            <span
              class="daw-ruler-marker daw-ruler-subbeat"
              style="left:${halfLeft}%"
            ></span>
          `);
        }
      }
    }

    rulerLines.innerHTML =
      markers.join('');
  }

  function timelineTimeFromPointer(event) {
    if (!timelineSurface || duration <= 0) return 0;

    const rect = timelineSurface.getBoundingClientRect();
    const x = Math.max(
      0,
      Math.min(rect.width, event.clientX - rect.left)
    );

    return (x / Math.max(1, rect.width)) * duration;
  }

  function timelineEntityId(prefix) {
    return `${prefix}-${Date.now()}-${Math.random()
      .toString(36)
      .slice(2,8)}`;
  }

  function addMarkerAt(time,label = null) {
    timelineMarkers.push({
      id:timelineEntityId('marker'),
      time:clamp(time,0,duration),
      label:label || `Marker ${timelineMarkers.length + 1}`
    });

    timelineMarkers.sort(
      (a,b) => a.time - b.time
    );

    renderMarkersAndRegions();
    scheduleLocalSave(0);
  }

  function addRegionAt(start,end,label = null,meta = {}) {
    const cleanStart = clamp(
      Math.min(start,end),
      0,
      duration
    );
    const cleanEnd = clamp(
      Math.max(start,end),
      0,
      duration
    );

    if (cleanEnd - cleanStart < 0.15) {
      return;
    }

    timelineRegions.push({
      id:timelineEntityId('region'),
      start:cleanStart,
      end:cleanEnd,
      label:label || `Note ${timelineRegions.length + 1}`,
      note:String(meta.note || label || ''),
      noteId:Number(meta.id || meta.note_id || 0),
      author:String(meta.author || cfg.currentUserName || ''),
      createdAt:String(meta.created_at || ''),
      shared:Boolean(meta.shared)
    });

    timelineRegions.sort(
      (a,b) => a.start - b.start
    );

    renderMarkersAndRegions();
    scheduleLocalSave(0);
  }

  async function shareRegionNote(start,end,note) {
    const endpoint=String(cfg.regionNoteEndpoint || '');
    if (!endpoint) throw new Error('REGION note sharing is unavailable.');
    const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf_token:String(cfg.csrf||''),track_id:Number(trackId),start:Number(start),end:Number(end),note:String(note||'')})});
    const data=await response.json().catch(()=>({ok:false,error:'Invalid server response.'}));
    if(!response.ok||!data.ok)throw new Error(data.error||'Could not share the REGION note.');
    return data.note||{};
  }

  function promptSharedRegionNote(start,end,prefill='') {
    const range=`${formatTime(start)}–${formatTime(end)}`;
    if(!regionNoteDialog||!regionNoteRange||!regionNoteText||!shareRegionNoteButton){return Promise.resolve(window.prompt(`Shared REGION note · ${range}`,String(prefill||'')));}
    regionNoteRange.textContent=range;regionNoteText.value=String(prefill||'');regionNoteText.removeAttribute('aria-invalid');openModal(regionNoteDialog);
    window.requestAnimationFrame(()=>{regionNoteText.focus();regionNoteText.setSelectionRange(regionNoteText.value.length,regionNoteText.value.length);});
    return new Promise(resolve=>{
      let settled=false;const backdrop=regionNoteDialog.querySelector('[data-close-region-note]');
      const finish=value=>{if(settled)return;settled=true;regionNoteDialog.removeEventListener('keydown',onKeyDown);backdrop?.removeEventListener('click',cancel);closeModal(regionNoteDialog);resolve(value);};
      const submit=()=>{const value=regionNoteText.value.trim();if(!value){regionNoteText.setAttribute('aria-invalid','true');regionNoteText.focus();return;}finish(value);};
      const cancel=()=>finish(null);
      const onKeyDown=event=>{if(event.key==='Escape'){event.preventDefault();cancel();}else if((event.ctrlKey||event.metaKey)&&event.key==='Enter'){event.preventDefault();submit();}};
      shareRegionNoteButton.onclick=submit;cancelRegionNote&&(cancelRegionNote.onclick=cancel);cancelRegionNoteTop&&(cancelRegionNoteTop.onclick=cancel);backdrop?.addEventListener('click',cancel);regionNoteDialog.addEventListener('keydown',onKeyDown);
    });
  }

  async function createSharedRegionNote(start,end,prefill='') {
    const note=await promptSharedRegionNote(start,end,prefill);
    if(note===null||!note.trim())return null;
    const saved=await shareRegionNote(start,end,note.trim());
    addRegionAt(Number(saved.start??start),Number(saved.end??end),String(saved.label||note.trim()).slice(0,80),saved);
    return saved;
  }

  function renderMarkersAndRegions() {
    if (!markerLane || duration <= 0) return;

    markerLane.innerHTML = [
      ...timelineRegions.map(region => {
        const left = (
          region.start / duration
        ) * 100;
        const width = (
          (region.end - region.start) /
          duration
        ) * 100;

        return `
          <div
            class="daw-region-item"
            data-region-id="${escapeHtml(region.id)}"
            style="left:${left}%;width:${Math.max(.08,width)}%"
          >
            <button
              type="button"
              class="daw-region-handle daw-region-handle-left"
              data-region-edge="start"
              aria-label="Resize region start"
            ></button>
            <button
              type="button"
              class="daw-region-body"
              data-region-body
              title="${escapeHtml(region.note || region.label)} · ${formatTime(region.start)}–${formatTime(region.end)}${region.author?` · ${escapeHtml(region.author)}`:''}"
            >
              <span>${region.shared?'NOTE · ':''}${escapeHtml(region.label)}</span>
            </button>
            <button
              type="button"
              class="daw-region-delete"
              data-region-delete
              aria-label="Delete region"
            >×</button>
            <button
              type="button"
              class="daw-region-handle daw-region-handle-right"
              data-region-edge="end"
              aria-label="Resize region end"
            ></button>
          </div>
        `;
      }),
      ...timelineMarkers.map(marker => {
        const left = (
          marker.time / duration
        ) * 100;

        return `
          <div
            class="daw-marker-item"
            data-marker-id="${escapeHtml(marker.id)}"
            style="left:${left}%"
          >
            <button
              type="button"
              class="daw-marker-body"
              data-marker-body
              title="${escapeHtml(marker.label)} · ${formatTime(marker.time)}"
            >
              <i></i>
              <span>${escapeHtml(marker.label)}</span>
            </button>
            <button
              type="button"
              class="daw-marker-delete"
              data-marker-delete
              aria-label="Delete marker"
            >×</button>
          </div>
        `;
      })
    ].join('');

    bindMarkerRegionEvents();
  }

  function bindMarkerRegionEvents() {
    markerLane
      ?.querySelectorAll('[data-marker-id]')
      .forEach(item => {
        const marker = timelineMarkers.find(
          entry =>
            entry.id === item.dataset.markerId
        );
        const body = item.querySelector(
          '[data-marker-body]'
        );

        if (!marker || !body) return;

        let pointerId = null;
        let moved = false;

        body.addEventListener('pointerdown',event => {
          if (event.button !== 0) return;

          pointerId = event.pointerId;
          moved = false;
          body.setPointerCapture(pointerId);
          event.preventDefault();
        });

        body.addEventListener('pointermove',event => {
          if (event.pointerId !== pointerId) {
            return;
          }

          moved = true;
          marker.time =
            timelineTimeFromPointer(event);

          item.style.left =
            `${(marker.time / duration) * 100}%`;
        });

        const finish = event => {
          if (event.pointerId !== pointerId) {
            return;
          }

          try {
            body.releasePointerCapture(pointerId);
          } catch (error) {}

          pointerId = null;

          if (moved) {
            timelineMarkers.sort(
              (a,b) => a.time - b.time
            );
            scheduleLocalSave();
          }
        };

        body.addEventListener('pointerup',finish);
        body.addEventListener(
          'pointercancel',
          finish
        );

        body.addEventListener('click',() => {
          if (moved) {
            moved = false;
            return;
          }

          seekTo(marker.time).catch(error => {
            console.error(
              'Marker seek failed',
              error
            );
          });
        });

        body.addEventListener('dblclick',event => {
          event.preventDefault();

          const label = window.prompt(
            'Marker name',
            marker.label
          );

          if (label !== null && label.trim()) {
            marker.label = label.trim().slice(0,80);
            renderMarkersAndRegions();
            scheduleLocalSave(0);
          }
        });

        item.querySelector('[data-marker-delete]')
          ?.addEventListener('click',event => {
            event.stopPropagation();

            timelineMarkers =
              timelineMarkers.filter(
                entry =>
                  entry.id !== marker.id
              );

            renderMarkersAndRegions();
            scheduleLocalSave(0);
          });
      });

    markerLane
      ?.querySelectorAll('[data-region-id]')
      .forEach(item => {
        const region = timelineRegions.find(
          entry =>
            entry.id === item.dataset.regionId
        );

        if (!region) return;

        const bindDrag = (
          element,
          mode
        ) => {
          if (!element) return;

          let pointerId = null;
          let startTime = 0;
          let originalStart = 0;
          let originalEnd = 0;
          let moved = false;

          element.addEventListener(
            'pointerdown',
            event => {
              if (event.button !== 0) return;

              pointerId = event.pointerId;
              startTime =
                timelineTimeFromPointer(event);
              originalStart = region.start;
              originalEnd = region.end;
              moved = false;

              element.setPointerCapture(
                pointerId
              );
              event.preventDefault();
              event.stopPropagation();
            }
          );

          element.addEventListener(
            'pointermove',
            event => {
              if (
                event.pointerId !== pointerId
              ) {
                return;
              }

              moved = true;
              const now =
                timelineTimeFromPointer(event);

              if (mode === 'start') {
                region.start = clamp(
                  now,
                  0,
                  region.end - .15
                );
              } else if (mode === 'end') {
                region.end = clamp(
                  now,
                  region.start + .15,
                  duration
                );
              } else {
                const delta = now - startTime;
                const length =
                  originalEnd -
                  originalStart;

                region.start = clamp(
                  originalStart + delta,
                  0,
                  Math.max(
                    0,
                    duration - length
                  )
                );
                region.end =
                  region.start + length;
              }

              const left =
                (region.start / duration) *
                100;
              const width =
                (
                  (region.end - region.start) /
                  duration
                ) * 100;

              item.style.left = `${left}%`;
              item.style.width =
                `${Math.max(.08,width)}%`;
            }
          );

          const finish = event => {
            if (
              event.pointerId !== pointerId
            ) {
              return;
            }

            try {
              element.releasePointerCapture(
                pointerId
              );
            } catch (error) {}

            pointerId = null;

            if (moved) {
              timelineRegions.sort(
                (a,b) => a.start - b.start
              );
              scheduleLocalSave();
            }
          };

          element.addEventListener(
            'pointerup',
            finish
          );
          element.addEventListener(
            'pointercancel',
            finish
          );

          if (mode === 'move') {
            element.addEventListener(
              'click',
              () => {
                if (moved) {
                  moved = false;
                  return;
                }

                setLoopRange(
                  region.start,
                  region.end,
                  true
                );

                seekTo(region.start).catch(
                  error => {
                    console.error(
                      'Region seek failed',
                      error
                    );
                  }
                );
              }
            );

            element.addEventListener(
              'dblclick',
              event => {
                event.preventDefault();

                // Notes are immutable collaboration history. A double-click
                // creates a follow-up instead of rewriting the original note.
                void createSharedRegionNote(region.start,region.end,region.note || region.label).catch(error=>window.alert(error.message));
              }
            );
          }
        };

        bindDrag(
          item.querySelector(
            '[data-region-body]'
          ),
          'move'
        );
        bindDrag(
          item.querySelector(
            '[data-region-edge="start"]'
          ),
          'start'
        );
        bindDrag(
          item.querySelector(
            '[data-region-edge="end"]'
          ),
          'end'
        );

        item.querySelector('[data-region-delete]')
          ?.addEventListener('click',event => {
            event.stopPropagation();

            timelineRegions =
              timelineRegions.filter(
                entry =>
                  entry.id !== region.id
              );

            renderMarkersAndRegions();
            scheduleLocalSave(0);
          });
      });
  }

  function updateLoopOverlay(start = loopStart, end = loopEnd, visible = null) {
    if (!loopSelection || duration <= 0) return;

    const hasRange = end > start;
    const shouldShow = visible === null ? hasRange : visible;

    loopSelection.hidden = !shouldShow;

    if (!shouldShow) return;

    const left = Math.max(0, Math.min(100, (start / duration) * 100));
    const right = Math.max(0, Math.min(100, (end / duration) * 100));

    loopSelection.style.left = `${left}%`;
    loopSelection.style.width = `${Math.max(0.05, right - left)}%`;

    if (loopLabel) {
      loopLabel.textContent = `${formatTime(start)} – ${formatTime(end)}`;
    }
  }

  function updateLoopButtons() {
    const hasRange = loopEnd > loopStart;

    if (loopToggleButton) {
      loopToggleButton.disabled = !hasRange;
      loopToggleButton.textContent = loopActive
        ? 'Loop: On'
        : 'Loop: Off';
      loopToggleButton.classList.toggle('active', loopActive);
    }

    if (loopClearButton) {
      loopClearButton.hidden = !hasRange;
    }
  }

  function setLoopRange(start, end, active = true) {
    loopStart = Math.max(0, Math.min(duration, Math.min(start, end)));
    loopEnd = Math.max(
      loopStart,
      Math.min(duration, Math.max(start, end))
    );

    if (loopEnd - loopStart < 0.15) {
      loopStart = 0;
      loopEnd = 0;
      loopActive = false;
    } else {
      loopActive = Boolean(active);
    }

    updateLoopOverlay();
    updateLoopButtons();
    scheduleLocalSave();
  }

  function clearLoop() {
    loopStart = 0;
    loopEnd = 0;
    loopActive = false;
    updateLoopOverlay(0, 0, false);
    updateLoopButtons();
    scheduleLocalSave();
  }

  ruler?.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;

    selectionPointerId = event.pointerId;
    selectionStartTime = timelineTimeFromPointer(event);
    selectionStartX = event.clientX;
    selectionDragged = false;
    selectingLoop = true;

    ruler.setPointerCapture(selectionPointerId);
    event.preventDefault();
  });

  ruler?.addEventListener('pointermove', event => {
    if (
      !selectingLoop ||
      event.pointerId !== selectionPointerId
    ) {
      return;
    }

    const current = timelineTimeFromPointer(event);
    const moved = Math.abs(event.clientX - selectionStartX);

    if (moved > 5) {
      selectionDragged = true;

      updateLoopOverlay(
        Math.min(selectionStartTime, current),
        Math.max(selectionStartTime, current),
        true
      );
    }
  });

  function finishTimelineSelection(event) {
    if (
      !selectingLoop ||
      event.pointerId !== selectionPointerId
    ) {
      return;
    }

    const current = timelineTimeFromPointer(event);

    try {
      ruler?.releasePointerCapture(selectionPointerId);
    } catch (error) {}

    selectingLoop = false;
    selectionPointerId = null;
    suppressSurfaceClickUntil = performance.now() + 80;

    if (selectionDragged) {
      const start = Math.min(selectionStartTime, current);
      const end = Math.max(selectionStartTime, current);

      setLoopRange(start, end, true);
      seekTo(start).catch(error => {
        console.error('Timeline seek failed', error);
      });
    } else {
      seekTo(current).catch(error => {
        console.error('Timeline seek failed', error);
      });
    }

    selectionDragged = false;
  }

  ruler?.addEventListener('pointerup', finishTimelineSelection);
  ruler?.addEventListener('pointercancel', event => {
    if (event.pointerId !== selectionPointerId) return;

    selectingLoop = false;
    selectionPointerId = null;
    selectionDragged = false;
    updateLoopOverlay();
  });

  timelineSurface?.addEventListener('click', event => {
    if (performance.now() < suppressSurfaceClickUntil) return;
    if (event.target.closest('.daw-ruler')) return;

    const trackArea = event.target.closest(
      '.daw-arrange-track,.daw-clip'
    );

    if (trackArea) {
      seekTo(
        timelineTimeFromPointer(event)
      ).catch(error => {
        console.error('Timeline seek failed', error);
      });
    }
  });

  timelineSnapToggle?.addEventListener(
    'click',
    () => {
      setEditSnapMode(
        editSnapMode === 'grid'
          ? 'free'
          : 'grid'
      );
    }
  );

  sessionTempoInput?.addEventListener(
    'change',
    () => {
      setSessionTempo(
        sessionTempoInput.value
      );
    }
  );

  sessionTempoInput?.addEventListener(
    'keydown',
    event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        sessionTempoInput.blur();
        setSessionTempo(
          sessionTempoInput.value
        );
      }
    }
  );

  resetSessionTempo?.addEventListener(
    'click',
    () => {
      setSessionTempo(
        sourceTempo
      );
    }
  );

  timelineZoomOut?.addEventListener('click',() => {
    setTimelineZoom(
      timelineZoom / 1.25
    );
  });

  timelineZoomIn?.addEventListener('click',() => {
    setTimelineZoom(
      timelineZoom * 1.25
    );
  });

  addTimelineMarker?.addEventListener(
    'click',
    () => {
      addMarkerAt(globalPosition());
    }
  );

  addTimelineRegion?.addEventListener(
    'click',
    async () => {
      if (loopEnd > loopStart) {
        try { await createSharedRegionNote(loopStart,loopEnd); } catch(error) { window.alert(error.message); }
        return;
      }

      const start = globalPosition();
      try { await createSharedRegionNote(start,Math.min(duration,start+Math.min(8,duration))); } catch(error) { window.alert(error.message); }
    }
  );

  loopToggleButton?.addEventListener('click', () => {
    if (loopEnd <= loopStart) return;

    loopActive = !loopActive;
    updateLoopButtons();
    scheduleLocalSave();
  });

  loopClearButton?.addEventListener('click', clearLoop);

  // Native trackpads already emit deltaX. This explicitly preserves
  // two-finger left/right scrolling while keeping song playback independent.
  dawArrange?.addEventListener(
    'wheel',
    event => {
      if (
        event.ctrlKey ||
        event.metaKey
      ) {
        event.preventDefault();

        /*
         * User-requested gesture direction:
         * spread outward => zoom out, pinch inward => zoom in.
         * Trackpad pinch is exposed as Ctrl/Meta + wheel by modern browsers,
         * so invert the conventional browser direction here.
         */
        setTimelineZoom(
          timelineZoom *
            (
              event.deltaY < 0
                ? 1 / 1.12
                : 1.12
            ),
          true,
          timelineTimeFromPointer(event)
        );

        return;
      }

      if (Math.abs(event.deltaX) > 0.1) {
        event.preventDefault();
        dawArrange.scrollLeft += event.deltaX;
      } else if (
        event.shiftKey &&
        Math.abs(event.deltaY) > 0.1
      ) {
        event.preventDefault();
        dawArrange.scrollLeft += event.deltaY;
      }
    },
    {passive:false}
  );

  const touchDistance = touches => {
    if (!touches || touches.length < 2) {
      return 0;
    }

    const dx =
      touches[1].clientX -
      touches[0].clientX;
    const dy =
      touches[1].clientY -
      touches[0].clientY;

    return Math.hypot(dx,dy);
  };

  dawArrange?.addEventListener(
    'touchstart',
    event => {
      if (event.touches.length !== 2) {
        return;
      }

      touchPinchStartDistance =
        touchDistance(event.touches);
      touchPinchStartZoom =
        timelineZoom;

      const midpointX =
        (
          event.touches[0].clientX +
          event.touches[1].clientX
        ) / 2;

      touchPinchAnchorTime =
        timelineTimeFromPointer({
          clientX:midpointX
        });

      event.preventDefault();
    },
    {passive:false}
  );

  dawArrange?.addEventListener(
    'touchmove',
    event => {
      if (
        event.touches.length !== 2 ||
        touchPinchStartDistance <= 0
      ) {
        return;
      }

      const distance =
        touchDistance(event.touches);

      if (distance <= 0) {
        return;
      }

      const scale =
        distance /
        touchPinchStartDistance;

      /*
       * Requested gesture semantics:
       * fingers farther apart => smaller zoom value (zoom out)
       * fingers closer together => larger zoom value (zoom in)
       */
      setTimelineZoom(
        touchPinchStartZoom /
          Math.max(.15,scale),
        false,
        touchPinchAnchorTime
      );

      event.preventDefault();
    },
    {passive:false}
  );

  const finishTouchPinch = event => {
    if (
      touchPinchStartDistance <= 0
    ) {
      return;
    }

    if (
      !event.touches ||
      event.touches.length < 2
    ) {
      touchPinchStartDistance = 0;
      touchPinchStartZoom =
        timelineZoom;
      scheduleLocalSave(0);
    }
  };

  dawArrange?.addEventListener(
    'touchend',
    finishTouchPinch,
    {passive:true}
  );
  dawArrange?.addEventListener(
    'touchcancel',
    finishTouchPinch,
    {passive:true}
  );

  function syncVerticalScroll(source, target) {
    if (!source || !target || syncingVerticalScroll) return;

    syncingVerticalScroll = true;
    target.scrollTop = source.scrollTop;

    requestAnimationFrame(() => {
      syncingVerticalScroll = false;
    });
  }

  dawArrange?.addEventListener('scroll', () => {
    syncVerticalScroll(dawArrange, trackList);
    scheduleLocalSave(260);
  });

  trackList?.addEventListener('scroll', () => {
    syncVerticalScroll(trackList, dawArrange);
    scheduleLocalSave(260);
  });

  mixerScroll?.addEventListener('scroll', () => {
    scheduleLocalSave(260);
  });

  window.addEventListener('resize', resizeTimelineSurface);

  // ---------------------------------------------------------
  // Handle-only drag/drop ordering.
  // ---------------------------------------------------------
  function currentOrder() {
    return stems.map(stem => stem.id);
  }

  function applyOrder(order) {
    const normalized = [];

    order.forEach(id => {
      const stem = stemById(id);

      if (stem && !normalized.includes(stem.id)) {
        normalized.push(stem.id);
      }
    });

    stems.forEach(stem => {
      if (!normalized.includes(stem.id)) {
        normalized.push(stem.id);
      }
    });

    const sorted = normalized
      .map(id => stemById(id))
      .filter(Boolean);

    stems.splice(0, stems.length, ...sorted);

    stems.forEach((stem, index) => {
      if (trackList && stem.leftRow) {
        trackList.appendChild(stem.leftRow);
      }

      if (arrangeLanes && stem.arrangeRow) {
        arrangeLanes.appendChild(stem.arrangeRow);
      }

      if (mixerScroll && stem.mixer) {
        mixerScroll.appendChild(stem.mixer);
      }

      const number = stem.mixer?.querySelector('.daw-channel-number');

      if (number) {
        number.textContent = String(index + 1).padStart(2, '0');
      }
    });

    scheduleLocalSave();
  }

  function moveStemBefore(sourceId, targetId) {
    if (sourceId === targetId) return;

    const order = currentOrder().filter(id => id !== sourceId);
    const targetIndex = order.indexOf(targetId);

    if (targetIndex < 0) {
      order.push(sourceId);
    } else {
      order.splice(targetIndex, 0, sourceId);
    }

    applyOrder(order);
  }

  function bindDragHandle(handle) {
    const parent = handle?.closest('[data-drag-stem]');
    if (!handle || !parent) return;

    handle.addEventListener('dragstart', event => {
      draggedStemId = Number(parent.dataset.dragStem || 0);
      dragSourceElement = parent;

      parent.classList.add('dragging');

      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData(
        'text/plain',
        String(draggedStemId)
      );
    });

    handle.addEventListener('dragend', () => {
      draggedStemId = 0;

      dragSourceElement?.classList.remove('dragging');
      dragSourceElement = null;

      document.querySelectorAll('.drag-over')
        .forEach(el => el.classList.remove('drag-over'));
    });
  }

  function bindDropZone(element) {
    if (!element) return;

    element.addEventListener('dragover', event => {
      if (!draggedStemId) return;

      event.preventDefault();
      event.dataTransfer.dropEffect = 'move';
      element.classList.add('drag-over');
    });

    element.addEventListener('dragleave', () => {
      element.classList.remove('drag-over');
    });

    element.addEventListener('drop', event => {
      if (!draggedStemId) return;

      event.preventDefault();
      element.classList.remove('drag-over');

      const sourceId = Number(
        event.dataTransfer.getData('text/plain') ||
        draggedStemId
      );
      const targetId = Number(element.dataset.dragStem || 0);

      if (sourceId && targetId) {
        moveStemBefore(sourceId, targetId);
      }
    });
  }

  document.querySelectorAll('[data-drag-handle]')
    .forEach(bindDragHandle);

  document.querySelectorAll('[data-drag-stem]')
    .forEach(bindDropZone);

  // ---------------------------------------------------------
  // Stable pan knobs and independent volume faders.
  // ---------------------------------------------------------
  stems.forEach(stem => {
    const knob = stem.knob;

    if (knob) {
      let dragStartY = 0;
      let dragStartValue = 0;
      let pointerId = null;

      knob.addEventListener('pointerdown', event => {
        if (event.button !== 0) return;

        pointerId = event.pointerId;
        dragStartY = event.clientY;
        dragStartValue = Number(stem.pan?.value || 0);

        knob.setPointerCapture(pointerId);
        knob.classList.add('dragging-knob');
        event.preventDefault();
        event.stopPropagation();
      });

      knob.addEventListener('pointermove', event => {
        if (pointerId !== event.pointerId) return;

        const delta = (dragStartY - event.clientY) / 85;
        setStemPan(stem, dragStartValue + delta);
      });

      const finishPanDrag = event => {
        if (pointerId !== event.pointerId) return;

        try {
          knob.releasePointerCapture(pointerId);
        } catch (error) {}

        pointerId = null;
        knob.classList.remove('dragging-knob');
      };

      knob.addEventListener('pointerup', finishPanDrag);
      knob.addEventListener('pointercancel', finishPanDrag);

      knob.addEventListener('dblclick', event => {
        event.preventDefault();
        event.stopPropagation();
        setStemPan(stem, 0);
      });

      knob.addEventListener('keydown', event => {
        const current = Number(stem.pan?.value || 0);
        const step = event.shiftKey ? 0.1 : 0.02;

        if (
          event.key === 'ArrowLeft' ||
          event.key === 'ArrowDown'
        ) {
          event.preventDefault();
          setStemPan(stem, current - step);
        } else if (
          event.key === 'ArrowRight' ||
          event.key === 'ArrowUp'
        ) {
          event.preventDefault();
          setStemPan(stem, current + step);
        } else if (
          event.key === 'Home' ||
          event.key === '0'
        ) {
          event.preventDefault();
          setStemPan(stem, 0);
        }
      });
    }

    const trimKnob =
      stem.trimKnob;

    if (trimKnob) {
      let trimPointerId = null;
      let trimDragStartY = 0;
      let trimDragStartValue = 0;

      trimKnob.addEventListener(
        'pointerdown',
        event => {
          if (event.button !== 0) {
            return;
          }

          trimPointerId =
            event.pointerId;
          trimDragStartY =
            event.clientY;
          trimDragStartValue =
            Number(stem.trimDb || 0);

          trimKnob.setPointerCapture(
            trimPointerId
          );

          trimKnob.classList.add(
            'dragging-knob'
          );

          event.preventDefault();
          event.stopPropagation();
        }
      );

      trimKnob.addEventListener(
        'pointermove',
        event => {
          if (
            trimPointerId !==
            event.pointerId
          ) {
            return;
          }

          const delta =
            (
              trimDragStartY -
              event.clientY
            ) /
            4.5;

          setTrackTrim(
            stem,
            trimDragStartValue +
              delta
          );
        }
      );

      const finishTrimDrag =
        event => {
          if (
            trimPointerId !==
            event.pointerId
          ) {
            return;
          }

          try {
            trimKnob.releasePointerCapture(
              trimPointerId
            );
          } catch (error) {}

          trimPointerId = null;

          trimKnob.classList.remove(
            'dragging-knob'
          );
        };

      trimKnob.addEventListener(
        'pointerup',
        finishTrimDrag
      );

      trimKnob.addEventListener(
        'pointercancel',
        finishTrimDrag
      );

      trimKnob.addEventListener(
        'dblclick',
        event => {
          event.preventDefault();
          event.stopPropagation();

          setTrackTrim(
            stem,
            0
          );
        }
      );

      trimKnob.addEventListener(
        'keydown',
        event => {
          const current =
            Number(
              stem.trimDb ||
              0
            );

          const step =
            event.shiftKey
              ? 2
              : 0.5;

          if (
            event.key ===
              'ArrowLeft' ||
            event.key ===
              'ArrowDown'
          ) {
            event.preventDefault();

            setTrackTrim(
              stem,
              current - step
            );
          } else if (
            event.key ===
              'ArrowRight' ||
            event.key ===
              'ArrowUp'
          ) {
            event.preventDefault();

            setTrackTrim(
              stem,
              current + step
            );
          } else if (
            event.key === 'Home' ||
            event.key === '0'
          ) {
            event.preventDefault();

            setTrackTrim(
              stem,
              0
            );
          }
        }
      );
    }

    stem.volume?.addEventListener('pointerdown', event => {
      event.stopPropagation();
    });

    stem.volume?.addEventListener('dragstart', event => {
      event.preventDefault();
    });
  });

  // ---------------------------------------------------------
  // Saved supervisor custom mixes.
  // ---------------------------------------------------------
  async function mixRequest(action, extra = {}) {
    if (!cfg.canSaveMix || !cfg.mixEndpoint) {
      throw new Error(
        'Saved mixes are not available for this account.'
      );
    }

    const response = await fetch(cfg.mixEndpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        action,
        track_id: trackId,
        csrf_token: cfg.csrf,
        ...extra
      })
    });

    const data = await response.json().catch(() => null);

    if (!response.ok || !data?.ok) {
      throw new Error(
        data?.error ||
        'Saved mix request failed.'
      );
    }

    return data;
  }

  function setSaveStatus(
    text,
    state = ''
  ) {
    if (!studioSaveStatus) {
      return;
    }

    studioSaveStatus.textContent =
      String(text || '');

    studioSaveStatus.classList.remove(
      'saving',
      'saved',
      'error'
    );

    if (state) {
      studioSaveStatus.classList.add(
        state
      );
    }

    if (
      text &&
      state === 'saved'
    ) {
      window.clearTimeout(
        setSaveStatus.timer
      );

      setSaveStatus.timer =
        window.setTimeout(
          () => {
            if (
              studioSaveStatus
                ?.classList.contains(
                  'saved'
                )
            ) {
              studioSaveStatus.textContent =
                '';
              studioSaveStatus.classList
                .remove('saved');
            }
          },
          2200
        );
    }
  }

  function setSelectedMixRef(
    mixId,
    mixName = ''
  ) {
    selectedMixId =
      Math.max(
        0,
        Number(mixId || 0)
      );

    selectedMixName =
      String(
        mixName ||
        ''
      ).trim();

    if (stemMixName) {
      stemMixName.value =
        selectedMixName ||
        projectTitle;
    }

    try {
      if (selectedMixId > 0) {
        localStorage.setItem(
          saveSelectionKey,
          JSON.stringify({
            id:selectedMixId,
            name:selectedMixName
          })
        );
      } else {
        localStorage.removeItem(
          saveSelectionKey
        );
      }
    } catch (error) {}
  }

  function restoreSelectedMixRef() {
    try {
      const raw =
        localStorage.getItem(
          saveSelectionKey
        );

      if (!raw) {
        return;
      }

      const saved =
        JSON.parse(raw);

      const id =
        Number(saved?.id || 0);

      if (id > 0) {
        selectedMixId = id;
        selectedMixName =
          String(
            saved?.name ||
            projectTitle
          ).trim() ||
          projectTitle;

        if (stemMixName) {
          stemMixName.value =
            selectedMixName;
        }
      }
    } catch (error) {}
  }

  function saveAsDefaultName() {
    const base =
      selectedMixName ||
      projectTitle;

    return selectedMixId > 0
      ? `${base} Copy`
      : base;
  }

  async function openSavedVersionsDialog(
    focusName = false
  ) {
    if (
      !cfg.canSaveMix ||
      !mixSaveDialog
    ) {
      return;
    }

    if (stemMixName) {
      stemMixName.value =
        focusName
          ? saveAsDefaultName()
          : (
              selectedMixName ||
              projectTitle
            );
    }

    openModal(
      mixSaveDialog
    );

    await refreshMixList();

    if (
      focusName &&
      stemMixName
    ) {
      requestAnimationFrame(
        () => {
          stemMixName.focus();
          stemMixName.select();
        }
      );
    }
  }

  async function saveCurrentVersion() {
    if (!cfg.canSaveMix) {
      return;
    }

    if (selectedMixId < 1) {
      setSaveStatus(
        'NAME REQUIRED'
      );

      await openSavedVersionsDialog(
        true
      );

      return;
    }

    const name =
      selectedMixName ||
      projectTitle;

    if (studioSaveButton) {
      studioSaveButton.disabled =
        true;
    }

    if (studioSaveAsButton) {
      studioSaveAsButton.disabled =
        true;
    }

    setSaveStatus(
      'SAVING…',
      'saving'
    );

    try {
      const data =
        await mixRequest(
          'save',
          {
            mix_id:selectedMixId,
            mix_name:name,
            state:collectMixState()
          }
        );

      setSelectedMixRef(
        Number(
          data.mix_id ||
          selectedMixId
        ),
        String(
          data.mix_name ||
          name
        )
      );

      setSaveStatus(
        'SAVED',
        'saved'
      );
    } catch (error) {
      if (
        /saved mix not found/i.test(
          String(
            error?.message ||
            ''
          )
        )
      ) {
        setSelectedMixRef(
          0,
          ''
        );

        setSaveStatus(
          'SAVE AS REQUIRED',
          'error'
        );

        await openSavedVersionsDialog(
          true
        );

        return;
      }

      setSaveStatus(
        'SAVE FAILED',
        'error'
      );

      alert(
        error?.message ||
        'Could not save the Studio version.'
      );
    } finally {
      if (studioSaveButton) {
        studioSaveButton.disabled =
          false;
      }

      if (studioSaveAsButton) {
        studioSaveAsButton.disabled =
          false;
      }
    }
  }

  function collectMixState() {
    const state = {
      sessionTempo:Number(sessionTempo),
      libraryClips:libraryClips.map(clip => ({
        id:clip.id,
        stemId:Number(clip.stemId || 0),
        name:clip.name,
        role:clip.role,
        song:clip.song,
        sourceTempo:Number(clip.sourceTempo || sourceTempo),
        sourceSignature:clip.sourceSignature,
        sourceDuration:Number(clip.sourceDuration || 0),
        sourceStart:Number(clip.sourceStart || 0),
        sourceEnd:Number(clip.sourceEnd || 0),
        timelineStart:Number(clip.timelineStart || 0),
        timelineLength:Number(clip.timelineLength || 0)
      })),
      masterVolume:Number(masterVolume?.value || 1),
      returns:{
        a:Number(auxReturnA?.value || 0.8),
        b:Number(auxReturnB?.value || 0.7)
      },
      groups:Object.fromEntries(
        Object.entries(groupState).map(
          ([key,state]) => [
            key,
            {
              volume:Number(state.volume || 0),
              muted:Boolean(state.muted)
            }
          ]
        )
      ),
      channelPlugins:Object.fromEntries(
        Object.entries(fixedPluginTargets).map(
          ([key,target]) => [
            key,
            target.plugins.map(plugin => ({
              type:plugin.type,
              enabled:plugin.enabled !== false,
              params:{...plugin.params}
            }))
          ]
        )
      ),
      customBuses:customBuses.map(bus => ({
        id:bus.id,
        name:bus.name,
        volume:Number(bus.volume || 0),
        muted:Boolean(bus.muted),
        plugins:bus.plugins.map(plugin => ({
          type:plugin.type,
          enabled:plugin.enabled !== false,
          params:{...plugin.params}
        }))
      })),
      markers:timelineMarkers.map(marker => ({
        id:marker.id,
        time:marker.time,
        label:marker.label
      })),
      regions:timelineRegions.map(region => ({
        id:region.id,
        start:region.start,
        end:region.end,
        label:region.label,
        note:region.note || '',
        note_id:Number(region.noteId || 0),
        author:region.author || '',
        created_at:region.createdAt || '',
        shared:Boolean(region.shared)
      })),
      plugins:{...pluginState},
      loop:{
        start:loopStart,
        end:loopEnd,
        active:loopActive
      },
      recording:{
        countInBars:Number(
          recordingCountInBars || 0
        ),
        metronome:Boolean(
          recordingMetronomeEnabled
        ),
        punch:Boolean(
          recordingPunchEnabled
        ),
        punchStart:Number(
          recordingPunchStart || 0
        ),
        punchEnd:Number(
          recordingPunchEnd || 0
        )
      },
      order:currentOrder(),
      stems:{}
    };

    stems.forEach(stem => {
      state.stems[String(stem.id)] = {
        volume:Number(stem.userGain || 0),
        pan:Number(stem.pan?.value || 0),
        trim:Number(stem.trimDb || 0),
        inputDeviceId:String(
          stem.recordingInputDeviceId || ''
        ),
        group:String(stem.group || 'direct'),
        muted:Boolean(stem.muted),
        solo:Boolean(stem.solo),
        sends:{
          a:Number(stem.sends.a || 0),
          b:Number(stem.sends.b || 0)
        },
        clips:(stem.clips || []).map(clip => ({
          id:clip.id,
          timelineStart:Number(clip.timelineStart || 0),
          timelineLength:Number(clip.timelineLength || 0),
          sourceStart:Number(clip.sourceStart || 0),
          sourceEnd:Number(clip.sourceEnd || 0),
          gainDb:Number(clip.gainDb || 0),
          muted:Boolean(clip.muted),
          fadeIn:Number(clip.fadeIn || 0),
          fadeOut:Number(clip.fadeOut || 0)
        })),
        automation:normalizeAutomation(stem.automation),
        plugins:stem.plugins.map(plugin => ({
          type:plugin.type,
          enabled:plugin.enabled !== false,
          params:{...plugin.params}
        }))
      };
    });

    return state;
  }


  function applyMixState(state) {
    if (!state || typeof state !== 'object') return;

    if (Array.isArray(state.order)) {
      applyOrder(state.order.map(Number));
    }

    setSessionTempo(
      Number(
        state.sessionTempoDefined === false
          ? sourceTempo
          : (
              state.sessionTempo ??
              sourceTempo
            )
      ),
      false
    );

    restoreLibraryClips(
      state.libraryClips
    );

    restoreCustomBuses(
      state.customBuses
    );

    const hasChannelPluginState =
      Boolean(
        state.channelPlugins &&
        typeof state.channelPlugins === 'object'
      ) &&
      state.channelPluginsDefined !== false;

    Object.entries(
      fixedPluginTargets
    ).forEach(([key,target]) => {
      target.plugins =
        hasChannelPluginState
          ? normalizeTrackPlugins(
              state.channelPlugins?.[key]
            )
          : normalizeTrackPlugins(
              defaultFixedTargetPlugins(key)
            );

      renderPluginTargetList(target);

      if (context) {
        rebuildPluginTargetGraph(target);
      }
    });

    stems.forEach(stem => {
      const mix = state.stems?.[String(stem.id)];
      if (!mix) return;

      stem.userGain = Math.max(
        0,
        Math.min(
          1.5,
          Number(mix.volume ?? stem.userGain)
        )
      );

      stem.trimDb = clamp(
        mix.trim ?? 0,
        -12,
        12
      );

      stem.recordingInputDeviceId =
        String(
          mix.inputDeviceId ||
          stem.recordingInputDeviceId ||
          ''
        );

      const requestedGroup =
        String(
          mix.group ||
          stem.group ||
          'direct'
        );

      stem.group = (
        [
          'direct',
          'vocals',
          'rhythm',
          'music'
        ].includes(requestedGroup) ||
        Boolean(
          customBusById(requestedGroup)
        )
      )
        ? requestedGroup
        : 'direct';

      stem.muted = Boolean(mix.muted);
      stem.solo = Boolean(mix.solo);
      stem.sends = {
        a:clamp(mix.sends?.a ?? 0,0,1),
        b:clamp(mix.sends?.b ?? 0,0,1)
      };
      stem.clips =
        normalizeStemClips(
          stem,
          mix.clipsDefined === false
            ? null
            : (
                Array.isArray(mix.clips)
                  ? mix.clips
                  : null
              )
        );
      stem.activeClipId = '';
      stem.automation =
        normalizeAutomation(mix.automation);
      stem.plugins =
        normalizeTrackPlugins(mix.plugins);

      setStemSend(stem,'a',stem.sends.a,false);
      setStemSend(stem,'b',stem.sends.b,false);
      setTrackTrim(stem,stem.trimDb,false);
      setTrackGroup(stem,stem.group,false);
      renderTrackPluginList(stem);
      renderStemClips(stem);
      renderAutomationLane(stem);

      if (context) {
        rebuildTrackPluginGraph(stem);
      }

      if (stem.volume) {
        stem.volume.value = String(stem.userGain);
      }

      setStemPan(stem, Number(mix.pan ?? 0));
    });

    const master = Math.max(
      0,
      Math.min(
        1.5,
        Number(state.masterVolume ?? 1)
      )
    );

    if (masterVolume) {
      masterVolume.value = String(master);
    }

    if (masterValue) {
      masterValue.textContent = dbText(master);
    }

    if (masterGain && context) {
      masterGain.gain.setTargetAtTime(
        master,
        context.currentTime,
        0.008
      );
    }

    setReturnLevel(
      'a',
      Number(state.returns?.a ?? 0.8),
      false
    );
    setReturnLevel(
      'b',
      Number(state.returns?.b ?? 0.7),
      false
    );

    Object.keys(groupState).forEach(group => {
      const saved = state.groups?.[group] || {};
      groupState[group].volume = clamp(
        saved.volume ?? 1,
        0,
        1.5
      );
      groupState[group].muted =
        Boolean(saved.muted);
      updateGroupBus(group,false);
    });

    timelineMarkers = Array.isArray(state.markers)
      ? state.markers.map((marker,index) => ({
          id:String(
            marker.id ||
            `marker-${Date.now()}-${index}`
          ),
          time:clamp(marker.time ?? 0,0,duration),
          label:String(marker.label || `Marker ${index + 1}`)
        }))
      : [];

    timelineRegions = Array.isArray(state.regions)
      ? state.regions
          .map((region,index) => ({
            id:String(
              region.id ||
              `region-${Date.now()}-${index}`
            ),
            start:clamp(region.start ?? 0,0,duration),
            end:clamp(region.end ?? 0,0,duration),
            label:String(region.label || `Note ${index + 1}`),
            note:String(region.note || region.label || ''),
            noteId:Number(region.note_id || 0),
            author:String(region.author || ''),
            createdAt:String(region.created_at || ''),
            shared:Boolean(region.shared)
          }))
          .filter(region => region.end > region.start)
      : [];

    const knownRegionNoteIds=new Set(timelineRegions.map(region=>Number(region.noteId||0)).filter(Boolean));
    (Array.isArray(cfg.regionNotes)?cfg.regionNotes:[]).forEach((note,index)=>{
      const noteId=Number(note.id||0);if(noteId&&knownRegionNoteIds.has(noteId))return;
      const start=clamp(note.start??0,0,duration),end=clamp(note.end??0,0,duration);if(end<=start)return;
      timelineRegions.push({id:`region-note-${noteId||index}`,start,end,label:String(note.label||`Note ${index+1}`),note:String(note.note||''),noteId,author:String(note.author||''),createdAt:String(note.created_at||''),shared:true});
      if(noteId)knownRegionNoteIds.add(noteId);
    });
    timelineRegions.sort((a,b)=>a.start-b.start);

    renderMarkersAndRegions();

    const focusRegionNoteId=Number(cfg.focusRegionNoteId||0);
    if(focusRegionNoteId>0){const focus=timelineRegions.find(region=>Number(region.noteId||0)===focusRegionNoteId);if(focus){setLoopRange(focus.start,focus.end,true);window.setTimeout(()=>{seekTo(focus.start).catch(()=>{});document.querySelector(`[data-region-id="${CSS.escape(String(focus.id))}"]`)?.scrollIntoView({block:'nearest',inline:'center'});},180);}}

    Object.entries(state.plugins || {})
      .forEach(([name, enabled]) => {
        setPluginState(name, Boolean(enabled));
      });

    if (
      state.loop &&
      Number(state.loop.end || 0) >
      Number(state.loop.start || 0)
    ) {
      setLoopRange(
        Number(state.loop.start || 0),
        Number(state.loop.end || 0),
        Boolean(state.loop.active)
      );
    } else {
      clearLoop();
    }

    if (
      state.recording &&
      typeof state.recording ===
        'object'
    ) {
      recordingCountInBars =
        [0,1,2,4].includes(
          Number(
            state.recording
              .countInBars || 0
          )
        )
          ? Number(
              state.recording
                .countInBars
            )
          : 0;

      recordingMetronomeEnabled =
        Boolean(
          state.recording
            .metronome
        );
      recordingPunchEnabled =
        Boolean(
          state.recording
            .punch
        );
      recordingPunchStart =
        Math.max(
          0,
          Number(
            state.recording
              .punchStart || 0
          )
        );
      recordingPunchEnd =
        Math.max(
          recordingPunchStart,
          Number(
            state.recording
              .punchEnd || 0
          )
        );

      updateRecordingSettingsUi();
      saveRecordingSettings();
    }

    updateGains();
  }

  async function refreshMixList() {
    if (!savedMixList || !cfg.canSaveMix) return;

    savedMixList.innerHTML =
      '<p class="daw-modal-empty">Loading saved mixes…</p>';

    try {
      const data = await mixRequest('list');
      const mixes = Array.isArray(data.mixes)
        ? data.mixes
        : [];

      if (!mixes.length) {
        savedMixList.innerHTML =
          '<p class="daw-modal-empty">No saved mixes yet.</p>';

        setSelectedMixRef(
          0,
          ''
        );
        return;
      }

      savedMixList.innerHTML = mixes.map(mix => `
        <article class="daw-saved-mix-row" data-mix-id="${Number(mix.id)}">
          <button type="button" data-load-mix="${Number(mix.id)}">
            <strong>${escapeHtml(mix.mix_name || 'My Mix')}</strong>
            <small>${escapeHtml(mix.updated_at || '')}</small>
          </button>
          <button
            type="button"
            class="daw-mix-delete"
            data-delete-mix="${Number(mix.id)}"
          >×</button>
        </article>
      `).join('');

      savedMixList
        .querySelectorAll('[data-load-mix]')
        .forEach(button => {
          button.addEventListener('click', async () => {
            try {
              const data = await mixRequest('load', {
                mix_id: Number(
                  button.dataset.loadMix || 0
                )
              });

              setSelectedMixRef(
                Number(
                  data.mix?.id ||
                  0
                ),
                String(
                  data.mix?.mix_name ||
                  projectTitle
                )
              );

              applyMixState(data.mix?.state || {});
              closeModal(mixSaveDialog);
              scheduleLocalSave(0);
            } catch (error) {
              alert(error.message);
            }
          });
        });

      savedMixList
        .querySelectorAll('[data-delete-mix]')
        .forEach(button => {
          button.addEventListener('click', async () => {
            const mixId = Number(
              button.dataset.deleteMix || 0
            );

            if (!confirm('Delete this saved mix?')) {
              return;
            }

            try {
              await mixRequest('delete', {
                mix_id: mixId
              });

              if (selectedMixId === mixId) {
                setSelectedMixRef(
                  0,
                  ''
                );

                setSaveStatus(
                  'VERSION DELETED'
                );
              }

              await refreshMixList();
            } catch (error) {
              alert(error.message);
            }
          });
        });
    } catch (error) {
      savedMixList.innerHTML =
        `<p class="daw-modal-empty">${escapeHtml(error.message)}</p>`;
    }
  }

  function closeTrackContextMenu() {
    if (!studioTrackContextMenu) {
      return;
    }

    studioTrackContextMenu.hidden = true;
    contextMenuStemId = 0;
  }

  function openTrackSettings(stem) {
    const details =
      stem?.leftRow?.querySelector(
        '.daw-track-details'
      );

    const form =
      details?.querySelector(
        '.stem-edit-form'
      );

    const summary =
      details?.querySelector(
        'summary'
      );

    if (
      !details ||
      !form ||
      !summary
    ) {
      return;
    }

    document.querySelectorAll(
      '.daw-track-details[open]'
    ).forEach(other => {
      if (other !== details) {
        other.removeAttribute('open');
      }
    });

    details.setAttribute(
      'open',
      ''
    );

    const rect =
      summary.getBoundingClientRect();

    const width = 230;
    const viewportPad = 8;

    /*
     * The track panel lives on the far-left edge. Open the settings card
     * inward/to the right of the sidebar instead of letting a native details
     * popup extend off the left side of the screen.
     */
    const left = Math.max(
      viewportPad,
      Math.min(
        window.innerWidth -
          width -
          viewportPad,
        rect.right + 8
      )
    );

    const estimatedHeight =
      Math.max(
        150,
        form.offsetHeight || 260
      );

    const top = Math.max(
      viewportPad,
      Math.min(
        window.innerHeight -
          estimatedHeight -
          viewportPad,
        rect.top
      )
    );

    form.style.left =
      `${Math.round(left)}px`;
    form.style.right = 'auto';
    form.style.top =
      `${Math.round(top)}px`;
  }

  function positionOpenTrackSettings() {
    document.querySelectorAll(
      '.daw-track-details[open]'
    ).forEach(details => {
      const stemId = Number(
        details.closest(
          '[data-stem-id]'
        )?.dataset.stemId || 0
      );

      const stem =
        stemById(stemId);

      if (stem) {
        openTrackSettings(stem);
      }
    });
  }

  async function deleteStudioStemById(
    stemId
  ) {
    const id = Number(stemId || 0);
    const stem = stemById(id);

    if (!id || !stem) {
      return false;
    }

    if (
      recordingActive ||
      recordingStopping ||
      recordingPendingSave
    ) {
      alert(
        recordingPendingSave
          ? 'Save or discard the stopped recording before deleting tracks.'
          : 'Stop the recording before deleting tracks.'
      );

      return false;
    }

    if (
      !window.confirm(
        `Delete ${stem.name || 'this track'} from the project?`
      )
    ) {
      return false;
    }

    await studioProjectRequest(
      'delete_stem',
      {
        track_id:trackId,
        stem_id:id
      }
    );

    window.location.reload();
    return true;
  }

  function openTrackContextMenu(
    stem,
    event
  ) {
    if (
      !studioTrackContextMenu ||
      !stem
    ) {
      return;
    }

    contextMenuStemId = stem.id;

    const armButton =
      studioTrackContextMenu.querySelector(
        '[data-context-track-arm]'
      );
    const muteButton =
      studioTrackContextMenu.querySelector(
        '[data-context-track-mute]'
      );
    const soloButton =
      studioTrackContextMenu.querySelector(
        '[data-context-track-solo]'
      );

    if (armButton) {
      armButton.textContent =
        recordingArmedStemId ===
          stem.id
          ? 'Disarm Recording'
          : 'Arm Recording';
    }

    if (muteButton) {
      muteButton.textContent =
        stem.muted
          ? 'Unmute'
          : 'Mute';
    }

    if (soloButton) {
      soloButton.textContent =
        stem.solo
          ? 'Unsolo'
          : 'Solo';
    }

    studioTrackContextMenu.hidden = false;

    const width = Math.max(
      158,
      studioTrackContextMenu.offsetWidth || 180
    );
    const height = Math.max(
      120,
      studioTrackContextMenu.offsetHeight || 190
    );

    const left = Math.max(
      8,
      Math.min(
        window.innerWidth -
          width -
          8,
        Number(event?.clientX || 0)
      )
    );

    const top = Math.max(
      8,
      Math.min(
        window.innerHeight -
          height -
          8,
        Number(event?.clientY || 0)
      )
    );

    studioTrackContextMenu.style.left =
      `${Math.round(left)}px`;
    studioTrackContextMenu.style.top =
      `${Math.round(top)}px`;
  }

  function setStudioMainMenu(open) {
    if (!studioMainMenu) {
      return;
    }

    const active = Boolean(open);

    studioMainMenu.hidden = !active;
    studioMainMenuToggle?.setAttribute(
      'aria-expanded',
      active ? 'true' : 'false'
    );

    if (active) {
      setSongInfoOpen(false);
      closeTrackRouteMenu();
      closeLibraryCategoryMenu();
    }
  }

  function setSongInfoOpen(open) {
    if (!songInfoMenu) {
      return;
    }

    const active = Boolean(open);

    songInfoMenu.hidden = !active;
    songInfoToggle?.setAttribute(
      'aria-expanded',
      active ? 'true' : 'false'
    );

    if (active) {
      setStudioMainMenu(false);
      closeTrackRouteMenu();
      closeLibraryCategoryMenu();
    }
  }

  async function studioProjectRequest(
    action,
    fields = {}
  ) {
    if (!projectEndpoint) {
      throw new Error(
        'Stem Studio project endpoint is unavailable.'
      );
    }

    const form = new FormData();
    form.append(
      'csrf_token',
      String(cfg.csrf || '')
    );
    form.append(
      'action',
      String(action || '')
    );

    Object.entries(fields)
      .forEach(([key,value]) => {
        if (
          value !== undefined &&
          value !== null
        ) {
          form.append(
            key,
            String(value)
          );
        }
      });

    const response = await fetch(
      projectEndpoint,
      {
        method:'POST',
        credentials:'same-origin',
        body:form
      }
    );

    const payload = await response
      .json()
      .catch(() => ({
        ok:false,
        error:'Invalid server response.'
      }));

    if (
      !response.ok ||
      !payload.ok
    ) {
      throw new Error(
        payload.error ||
        `Studio request failed (${response.status}).`
      );
    }

    return payload;
  }

  function setRecordingStatus(
    text,
    state = ''
  ) {
    if (!studioRecordStatus) {
      return;
    }

    studioRecordStatus.textContent =
      String(text || '');

    studioRecordStatus.classList.remove(
      'ready',
      'recording',
      'error'
    );

    if (state) {
      studioRecordStatus.classList.add(
        state
      );
    }
  }

  function removeLiveRecordingVisual() {
    recordingLiveElement?.remove();

    recordingLiveElement = null;
    recordingLiveCanvas = null;
    recordingLiveMins = [];
    recordingLiveMaxs = [];
    recordingLiveLastTimelineResize = 0;
  }

  function ensureLiveRecordingVisual() {
    const stem =
      armedRecordingStem();

    if (
      !stem?.mainClipLayer
    ) {
      return null;
    }

    if (
      recordingLiveElement &&
      recordingLiveElement.isConnected
    ) {
      return recordingLiveElement;
    }

    removeLiveRecordingVisual();

    const element =
      document.createElement(
        'div'
      );

    element.className =
      'daw-live-recording-clip';

    element.innerHTML = `
      <div class="daw-live-recording-head">
        <strong>REC</strong>
        <span data-live-record-time>0:00</span>
      </div>
      <canvas
        data-live-record-waveform
        aria-hidden="true"
      ></canvas>
    `;

    stem.mainClipLayer.appendChild(
      element
    );

    recordingLiveElement =
      element;
    recordingLiveCanvas =
      element.querySelector(
        '[data-live-record-waveform]'
      );

    return element;
  }

  function appendLiveRecordingPeak(
    audioBuffer
  ) {
    if (
      !audioBuffer ||
      !recordingActive
    ) {
      return;
    }

    let min = 1;
    let max = -1;
    const channels = Math.max(
      1,
      Math.min(
        2,
        Number(
          audioBuffer.numberOfChannels ||
          1
        )
      )
    );

    for (
      let channel = 0;
      channel < channels;
      channel++
    ) {
      const data =
        audioBuffer.getChannelData(
          channel
        );

      const stride =
        Math.max(
          1,
          Math.floor(
            data.length / 512
          )
        );

      for (
        let index = 0;
        index < data.length;
        index += stride
      ) {
        const sample =
          Number(
            data[index] || 0
          );

        if (sample < min) {
          min = sample;
        }

        if (sample > max) {
          max = sample;
        }
      }
    }

    recordingLiveMins.push(
      Math.max(
        -1,
        Math.min(
          1,
          min
        )
      )
    );
    recordingLiveMaxs.push(
      Math.max(
        -1,
        Math.min(
          1,
          max
        )
      )
    );

    const limit = 6000;

    if (
      recordingLiveMins.length >
      limit
    ) {
      const remove =
        recordingLiveMins.length -
        limit;

      recordingLiveMins.splice(
        0,
        remove
      );
      recordingLiveMaxs.splice(
        0,
        remove
      );
    }
  }

  function maybeExtendTimelineForRecording(
    timelineNow
  ) {
    const now =
      Math.max(
        recordingStartTimeline,
        Number(
          timelineNow ||
          recordingStartTimeline
        )
      );

    if (
      now <= duration - .15
    ) {
      return;
    }

    const stamp =
      performance.now();

    if (
      stamp -
      recordingLiveLastTimelineResize <
      500
    ) {
      return;
    }

    recordingLiveLastTimelineResize =
      stamp;

    duration =
      Math.max(
        duration,
        now + 1
      );

    if (timelineSurface) {
      timelineSurface.dataset.duration =
        String(duration);
    }

    resizeTimelineSurface();

    stems.forEach(stem => {
      renderStemClips(stem);
    });
  }

  function drawLiveRecordingVisual(
    timelineNow = globalPosition()
  ) {
    if (
      !recordingActive &&
      !recordingCountInActive
    ) {
      return;
    }

    const element =
      ensureLiveRecordingVisual();

    if (!element) {
      return;
    }

    const now =
      Math.max(
        recordingStartTimeline,
        Number(
          timelineNow ||
          recordingStartTimeline
        )
      );

    maybeExtendTimelineForRecording(
      now
    );

    const start =
      Math.max(
        0,
        recordingStartTimeline
      );
    const length =
      Math.max(
        .04,
        now - start
      );

    element.style.left =
      `${Math.min(
        100,
        (
          start /
          Math.max(
            .05,
            duration
          )
        ) *
        100
      )}%`;

    element.style.width =
      `${Math.max(
        .18,
        Math.min(
          100,
          (
            length /
            Math.max(
              .05,
              duration
            )
          ) *
          100
        )
      )}%`;

    const time =
      element.querySelector(
        '[data-live-record-time]'
      );

    if (time) {
      time.textContent =
        recordingCountInActive
          ? 'COUNT'
          : formatTime(length);
    }

    const canvas =
      recordingLiveCanvas;

    if (!canvas) {
      return;
    }

    const width =
      Math.max(
        1,
        Math.floor(
          canvas.clientWidth || 1
        )
      );
    const height =
      Math.max(
        1,
        Math.floor(
          canvas.clientHeight || 1
        )
      );
    const ratio =
      Math.max(
        1,
        Math.min(
          2,
          window.devicePixelRatio ||
          1
        )
      );

    canvas.width =
      Math.max(
        1,
        Math.floor(
          width * ratio
        )
      );
    canvas.height =
      Math.max(
        1,
        Math.floor(
          height * ratio
        )
      );

    const ctx =
      canvas.getContext('2d');

    if (!ctx) {
      return;
    }

    ctx.setTransform(
      ratio,
      0,
      0,
      ratio,
      0,
      0
    );
    ctx.clearRect(
      0,
      0,
      width,
      height
    );

    const count =
      recordingLiveMins.length;

    if (!count) {
      ctx.globalAlpha = .3;
      ctx.fillStyle = '#ee8a80';
      ctx.fillRect(
        0,
        Math.floor(
          height / 2
        ),
        width,
        1
      );
      ctx.globalAlpha = 1;
      return;
    }

    const mid =
      height / 2;
    const amplitude =
      Math.max(
        1,
        height * .42
      );

    ctx.fillStyle =
      '#ef9188';
    ctx.globalAlpha =
      .86;

    for (
      let x = 0;
      x < width;
      x++
    ) {
      const fraction =
        width <= 1
          ? 0
          : x /
            (width - 1);
      const index =
        Math.min(
          count - 1,
          Math.floor(
            fraction *
            count
          )
        );
      const minValue =
        Number(
          recordingLiveMins[
            index
          ] || 0
        );
      const maxValue =
        Number(
          recordingLiveMaxs[
            index
          ] || 0
        );

      const yTop =
        mid -
        maxValue *
        amplitude;
      const yBottom =
        mid -
        minValue *
        amplitude;

      ctx.fillRect(
        x,
        yTop,
        1,
        Math.max(
          1,
          yBottom -
          yTop
        )
      );
    }

    ctx.globalAlpha = 1;
  }

  function recalculateProjectDuration() {
    duration =
      Math.max(
        .05,
        ...stems.flatMap(
          stem =>
            (stem.clips || [])
              .map(
                clip =>
                  Number(
                    clip.timelineStart ||
                    0
                  ) +
                  Number(
                    clip.timelineLength ||
                    0
                  )
              )
        ),
        ...libraryClips.map(
          clip =>
            Number(
              clip.timelineStart ||
              0
            ) +
            Number(
              clip.timelineLength ||
              0
            )
        )
      );

    if (timelineSurface) {
      timelineSurface.dataset.duration =
        String(duration);
    }

    resizeTimelineSurface();

    stems.forEach(stem => {
      renderStemClips(stem);
    });
  }

  function refreshRecordedStemInPlace(
    result,
    name
  ) {
    const stem =
      stemById(
        Number(
          result?.stem_id ||
          recordingArmedStemId ||
          0
        )
      );

    if (!stem) {
      throw new Error(
        'The saved recording track is no longer available in Studio.'
      );
    }

    const sourceDuration =
      Math.max(
        .05,
        Number(
          result?.duration ||
          0
        )
      );
    const offset =
      Math.max(
        0,
        Number(
          result?.start_offset ||
          recordingStartTimeline ||
          0
        )
      );
    const sourceTempoValue =
      Math.max(
        40,
        Math.min(
          300,
          Number(
            result?.source_tempo ||
            sessionTempo ||
            sourceTempo
          )
        )
      );
    const ratio =
      sourceTempoValue /
      sourceTempo;
    const version =
      Date.now();
    const baseUrl =
      String(
        result?.media_url ||
        `${stemMediaBase}${stem.id}`
      );
    const mediaUrl =
      `${baseUrl}` +
      `${baseUrl.includes('?') ? '&' : '?'}` +
      `v=${version}`;

    stem.name =
      String(
        name ||
        result?.track_name ||
        stem.name ||
        stem.label ||
        'Audio Recording'
      );
    stem.label =
      stem.name;
    stem.role =
      String(
        result?.stem_role ||
        stem.role ||
        'Other'
      );
    stem.duration =
      sourceDuration;
    stem.offset =
      offset;
    stem.initialSourceStart =
      0;
    stem.initialSourceEnd =
      sourceDuration;
    stem.sourceTempo =
      sourceTempoValue;
    stem.timelineRatio =
      ratio;
    stem.url =
      mediaUrl;
    stem.waveformVersion =
      version;
    stem.waveformData =
      null;
    stem.waveformError =
      false;
    stem.waveformLoading =
      false;
    stem.waveformQueued =
      false;
    stem.isEmptyRecordingTrack =
      false;
    stem.takeOfStemId =
      0;
    stem.activeClipId =
      '';
    stem.activeCrossfadeClipId =
      '';

    stem.clips = [{
      id:
        `stem-${stem.id}-clip-${version}`,
      timelineStart:
        offset,
      timelineLength:
        Math.max(
          .05,
          sourceDuration *
          ratio
        ),
      sourceStart:0,
      sourceEnd:
        sourceDuration,
      gainDb:0,
      muted:false,
      fadeIn:0,
      fadeOut:0
    }];

    [
      stem.audio,
      stem.crossfadeAudio
    ].forEach(audio => {
      if (!audio) {
        return;
      }

      audio.pause();
      audio.src =
        mediaUrl;
      audio.load();

      setMediaTempo(
        audio,
        sessionTempo /
        sourceTempoValue
      );
    });

    stem.leftRow
      ?.classList.remove(
        'daw-empty-recording-track',
        'daw-take-lane'
      );
    stem.mixer
      ?.classList.remove(
        'daw-empty-recording-track',
        'daw-take-lane'
      );
    stem.arrangeRow
      ?.classList.remove(
        'daw-empty-recording-track',
        'daw-take-lane'
      );

    const leftName =
      stem.leftRow
        ?.querySelector(
          '.daw-track-select strong'
        );

    if (leftName) {
      leftName.textContent =
        stem.name;
    }

    const mixerName =
      stem.mixer
        ?.querySelector(
          '.daw-channel-head strong,' +
          '.daw-mixer-channel-name strong,' +
          '.daw-channel-name strong'
        );

    if (mixerName) {
      mixerName.textContent =
        stem.name;
    }

    renderStemClips(
      stem
    );

    recalculateProjectDuration();

    queueStemWaveform(
      stem
    );

    setArmedStem(
      stem.id
    );
    markSelectedStem(
      stem.id
    );

    selectedArrangementClip = {
      kind:'stem',
      ownerId:String(
        stem.id
      ),
      clipId:String(
        stem.clips[0].id
      )
    };

    const clipElement =
      stem.mainClipLayer
        ?.querySelector(
          `[data-main-clip-id="${stem.clips[0].id}"]`
        );

    clipElement?.classList.add(
      'selected'
    );

    removeLiveRecordingVisual();
    updateTrackInspector();
    updateGains();
    saveLocalStateNow();

    return stem;
  }

  function formatRecordingBytes(
    bytes
  ) {
    const clean = Math.max(
      0,
      Number(bytes || 0)
    );

    if (clean >= 1024 * 1024) {
      return `${(
        clean /
        (1024 * 1024)
      ).toFixed(1)} MB`;
    }

    return `${Math.max(
      0,
      Math.round(
        clean / 1024
      )
    )} KB`;
  }

  function requireArmedRecordingTrack() {
    const armed =
      armedRecordingStem();

    if (armed) {
      return armed;
    }

    setRecordingStatus(
      'ARM A TRACK',
      'error'
    );

    [
      ...document.querySelectorAll(
        '[data-sidebar-track-arm],' +
        '[data-track-arm]'
      )
    ].forEach(button => {
      button.classList.add(
        'needs-arm'
      );
    });

    window.setTimeout(
      () => {
        document.querySelectorAll(
          '.needs-arm'
        ).forEach(button =>
          button.classList.remove(
            'needs-arm'
          )
        );
      },
      1800
    );

    return null;
  }

  function resetRecordingCaptureState() {
    recordingCapturedBytes = 0;
    recordingSignalPeak = 0;
    recordingUploadError = null;
    inputClipLatched = false;

    studioInputMeter
      ?.classList.remove(
        'clipping'
      );
  }

  function showRecordingSavePrompt(
    status
  ) {
    const armed =
      armedRecordingStem();
    const duration =
      Math.max(
        0,
        Number(
          status?.duration || 0
        )
      );
    const bytes =
      Math.max(
        0,
        Number(
          status?.pcm_bytes || 0
        )
      );
    const name =
      String(
        status?.track_name ||
        recordingTrackName()
      ).trim() ||
      'Audio Recording';

    recordingPendingSave = {
      id:String(
        status?.recording_id ||
        recordingId ||
        ''
      ),
      targetStemId:Number(
        status?.target_stem_id ||
        recordingArmedStemId ||
        0
      ),
      duration,
      bytes,
      name,
      signalPeak:
        recordingSignalPeak
    };

    if (recordingSaveTrack) {
      recordingSaveTrack.textContent =
        armed
          ? `Track · ${armed.name || armed.label}`
          : 'Armed track';
    }

    if (recordingSaveStats) {
      recordingSaveStats.textContent =
        `${formatTime(duration)} · ${formatRecordingBytes(bytes)}`;
    }

    if (recordingSaveSignal) {
      const peak =
        Number(
          recordingSignalPeak || 0
        );

      recordingSaveSignal.textContent =
        peak >= .002
          ? `Input signal captured · peak ${(
              20 *
              Math.log10(
                Math.max(
                  peak,
                  .000001
                )
              )
            ).toFixed(1)} dBFS`
          : 'Recording data captured · very low/no input signal detected';

      recordingSaveSignal.classList.toggle(
        'warning',
        peak < .002
      );
    }

    if (recordingSaveName) {
      recordingSaveName.value =
        name;
    }

    setRecordingStatus(
      'READY TO SAVE',
      'ready'
    );

    openModal(
      recordingSaveDialog
    );

    requestAnimationFrame(
      () => {
        recordingSaveName?.focus();
        recordingSaveName?.select();
      }
    );

    updateRecordingSettingsUi();
  }

  async function savePendingRecording() {
    if (
      !recordingPendingSave ||
      !recordingId
    ) {
      return;
    }

    const name =
      String(
        recordingSaveName?.value ||
        recordingPendingSave.name ||
        'Audio Recording'
      ).trim();

    if (!name) {
      recordingSaveName?.focus();
      return;
    }

    if (saveRecordingButton) {
      saveRecordingButton.disabled =
        true;
      saveRecordingButton.textContent =
        'Saving…';
    }

    if (discardRecordingButton) {
      discardRecordingButton.disabled =
        true;
    }

    setRecordingStatus(
      'SAVING RECORDING…',
      'recording'
    );

    try {
      const id =
        recordingId;

      const result =
        await studioProjectRequest(
          'recording_finish',
          {
            track_id:trackId,
            recording_id:id,
            track_name:name
          }
        );

      const savedStem =
        refreshRecordedStemInPlace(
          result,
          name
        );

      recordingId = '';
      recordingPendingSave = null;

      closeModal(
        recordingSaveDialog
      );

      setRecordingTransportLock(
        false
      );

      setRecordingStatus(
        'RECORDING SAVED',
        'ready'
      );

      recordingOriginalDuration =
        duration;

      if (savedStem) {
        setArmedStem(
          savedStem.id
        );
      }

      studioRecordButton &&
        (studioRecordButton.disabled =
          false);

      updateRecordingSettingsUi();
      window.STONEFELLOW_LIVE_RECORDING_V87?.refreshAfterCoreSave?.();
    } catch (error) {
      setRecordingStatus(
        error?.message ||
        'SAVE FAILED',
        'error'
      );

      console.error(
        'Recording save failed',
        error
      );
    } finally {
      if (saveRecordingButton) {
        saveRecordingButton.disabled =
          false;
        saveRecordingButton.textContent =
          'Save Recording';
      }

      if (discardRecordingButton) {
        discardRecordingButton.disabled =
          false;
      }

      updateRecordingSettingsUi();
    }
  }

  async function discardPendingRecording() {
    if (
      !recordingPendingSave &&
      !recordingId
    ) {
      closeModal(
        recordingSaveDialog
      );
      return;
    }

    if (saveRecordingButton) {
      saveRecordingButton.disabled =
        true;
    }

    if (discardRecordingButton) {
      discardRecordingButton.disabled =
        true;
      discardRecordingButton.textContent =
        'Discarding…';
    }

    try {
      await cancelServerRecording();

      recordingPendingSave = null;

      closeModal(
        recordingSaveDialog
      );

      removeLiveRecordingVisual();

      duration =
        Math.max(
          .05,
          recordingOriginalDuration ||
          duration
        );

      resizeTimelineSurface();

      stems.forEach(stem => {
        renderStemClips(stem);
      });

      setRecordingTransportLock(
        false
      );

      setRecordingStatus(
        'RECORDING DISCARDED'
      );
      window.STONEFELLOW_LIVE_RECORDING_V87?.refreshAfterCoreSave?.();
    } finally {
      if (saveRecordingButton) {
        saveRecordingButton.disabled =
          false;
      }

      if (discardRecordingButton) {
        discardRecordingButton.disabled =
          false;
        discardRecordingButton.textContent =
          'Discard';
      }

      updateRecordingSettingsUi();
    }
  }

  function armedRecordingStem() {
    return stemById(
      recordingArmedStemId
    );
  }

  function selectedRecordingDeviceId() {
    const armed =
      armedRecordingStem();

    return String(
      armed?.recordingInputDeviceId ||
      armed?.inputSelect?.value ||
      studioAudioInput?.value ||
      ''
    );
  }

  function activeRecordingDeviceId() {
    return String(
      recordingStream
        ?.getAudioTracks?.()[0]
        ?.getSettings?.()
        ?.deviceId ||
      ''
    );
  }

  function recordingDeviceLabel() {
    const armed =
      armedRecordingStem();

    const selected =
      armed?.inputSelect
        ?.selectedOptions?.[0] ||
      studioAudioInput
        ?.selectedOptions?.[0];

    return String(
      selected?.dataset.deviceLabel ||
      selected?.textContent ||
      recordingStream
        ?.getAudioTracks?.()[0]
        ?.label ||
      'Audio Input'
    )
      .replace(
        /^FOCUSRITE\s*·\s*/i,
        ''
      )
      .trim();
  }

  function recordingTrackName() {
    const stem =
      stemById(
        recordingArmedStemId
      );

    if (stem) {
      if (stem.isEmptyRecordingTrack) {
        return String(
          stem.name ||
          stem.label ||
          'Audio Track'
        ).trim() || 'Audio Track';
      }

      const base =
        String(
          stem.name ||
          stem.label ||
          'Audio'
        )
          .replace(
            /\s+Take\s+\d+$/i,
            ''
          )
          .trim() ||
        'Audio';

      const existing =
        stems.filter(item =>
          String(
            item.name ||
            item.label ||
            ''
          )
            .toLowerCase()
            .startsWith(
              `${base.toLowerCase()} take `
            )
        ).length;

      return `${base} Take ${existing + 1}`;
    }

    const recordings =
      stems.filter(item =>
        /^audio recording\b/i.test(
          String(
            item.name ||
            item.label ||
            ''
          )
        )
      ).length;

    return `Audio Recording ${recordings + 1}`;
  }

  function setArmedStem(stemId) {
    const next =
      stemById(stemId)
        ? Number(stemId)
        : 0;

    recordingArmedStemId =
      recordingArmedStemId === next
        ? 0
        : next;

    stems.forEach(stem => {
      const armed =
        stem.id ===
        recordingArmedStemId;

      stem.leftRow?.classList.toggle(
        'armed',
        armed
      );
      stem.mixer?.classList.toggle(
        'armed',
        armed
      );

      [
        stem.armButton,
        stem.sidebarArmButton
      ].forEach(button => {
        button?.classList.toggle(
          'active',
          armed
        );

        button?.setAttribute(
          'aria-pressed',
          armed ? 'true' : 'false'
        );

        button?.classList.remove(
          'needs-arm'
        );
      });
    });

    if (recordingActive) {
      updateTrackInspector();
      return;
    }

    const armed =
      stemById(
        recordingArmedStemId
      );

    if (
      armed?.recordingInputDeviceId &&
      studioAudioInput
    ) {
      const exists =
        [
          ...studioAudioInput.options
        ].some(
          option =>
            option.value ===
            armed.recordingInputDeviceId
        );

      if (exists) {
        studioAudioInput.value =
          armed.recordingInputDeviceId;
      }
    }

    setRecordingStatus(
      armed
        ? `ARMED · ${armed.name || armed.label}`
        : (
            recordingStream
              ? 'READY · ARM A TRACK'
              : 'ARM A TRACK'
          ),
      armed
        ? 'ready'
        : ''
    );

    updateTrackInspector();
    updateRecordingSettingsUi();
  }

  function disconnectRecordingInputGraph(
    stopStream = true
  ) {
    cancelAnimationFrame(
      recordingMeterFrame
    );
    recordingMeterFrame = 0;

    [
      recordingProcessor,
      recordingProcessorSink,
      recordingInputSource,
      recordingInputAnalyser,
      recordingMonitorGain,
      recordingMeterSink
    ].forEach(node => {
      if (!node) return;

      try {
        node.disconnect();
      } catch (error) {}
    });

    recordingProcessor = null;
    recordingProcessorSink = null;
    recordingInputSource = null;
    recordingInputAnalyser = null;
    recordingMonitorGain = null;
    recordingMeterSink = null;
    recordingMeterData = null;

    if (
      stopStream &&
      recordingStream
    ) {
      recordingStream
        .getTracks()
        .forEach(track =>
          track.stop()
        );

      recordingStream = null;
    }

    studioInputMeter?.style.setProperty(
      '--input-level',
      '0%'
    );
  }

  function updateRecordingMonitor() {
    if (
      recordingMonitorGain &&
      context
    ) {
      recordingMonitorGain.gain
        .setTargetAtTime(
          recordingMonitorEnabled
            ? 1
            : 0,
          context.currentTime,
          0.008
        );
    }

    studioMonitorButton
      ?.classList.toggle(
        'active',
        recordingMonitorEnabled
      );

    studioMonitorButton
      ?.setAttribute(
        'aria-pressed',
        recordingMonitorEnabled
          ? 'true'
          : 'false'
      );
  }

  function setRecordingTransportLock(
    locked
  ) {
    const active =
      Boolean(locked);

    document.body.classList.toggle(
      'daw-recording-locked',
      active
    );

    if (playButton) {
      playButton.disabled =
        active;
    }

    [
      ruler,
      timelineSurface
    ].forEach(element => {
      element?.setAttribute(
        'aria-disabled',
        active
          ? 'true'
          : 'false'
      );
    });
  }

  function recordingBeatSeconds() {
    const {
      denominator
    } = timelineSignatureParts();

    return Math.max(
      0.05,
      (
        60 /
        Math.max(
          40,
          Number(
            sessionTempo || 120
          )
        )
      ) *
      (
        4 /
        denominator
      )
    );
  }

  function recordingBarSeconds() {
    const {
      numerator
    } = timelineSignatureParts();

    return recordingBeatSeconds() *
      numerator;
  }

  function setRecordingCountInBarsValue(value) {
    const next = Number(value || 0);
    recordingCountInBars =
      [0,1,2,4].includes(next)
        ? next
        : 0;

    saveRecordingSettings();
    updateRecordingSettingsUi();
  }

  function setMetronomeEnabled(enabled, audition = false) {
    recordingMetronomeEnabled =
      Boolean(enabled);
    metronomeLastBeat = -1;

    saveRecordingSettings();
    updateRecordingSettingsUi();

    if (recordingMetronomeEnabled && audition) {
      ensureAudioGraph();

      if (context?.state === 'suspended') {
        context.resume()
          .then(() => playMetronomeClick(true))
          .catch(() => {});
      } else {
        playMetronomeClick(true);
      }
    }
  }

  function saveRecordingSettings() {
    try {
      localStorage.setItem(
        recordingSettingsStorageKey,
        JSON.stringify({
          countInBars:
            recordingCountInBars,
          metronome:
            recordingMetronomeEnabled,
          punch:
            recordingPunchEnabled,
          punchStart:
            recordingPunchStart,
          punchEnd:
            recordingPunchEnd
        })
      );
    } catch (error) {}
  }

  function updateRecordingSettingsUi() {
    if (recordCountInBars) {
      recordCountInBars.value =
        String(
          recordingCountInBars
        );
    }

    if (studioMetronomeCountIn) {
      studioMetronomeCountIn.value =
        String(recordingCountInBars);
    }

    studioMetronomeButton
      ?.classList.toggle(
        'active',
        recordingMetronomeEnabled
      );

    if (studioMetronomeButton) {
      studioMetronomeButton.title =
        recordingMetronomeEnabled
          ? 'Metronome on · Open settings'
          : 'Metronome off · Open settings';
    }

    studioMetronomeToggle
      ?.classList.toggle(
        'active',
        recordingMetronomeEnabled
      );
    studioMetronomeToggle
      ?.setAttribute(
        'aria-pressed',
        recordingMetronomeEnabled
          ? 'true'
          : 'false'
      );

    if (studioMetronomeToggle) {
      studioMetronomeToggle.textContent =
        recordingMetronomeEnabled
          ? 'ON'
          : 'OFF';
    }

    if (studioMetronomeTempo) {
      studioMetronomeTempo.textContent =
        `${Number(sessionTempo || sourceTempo || 120).toFixed(1).replace(/\.0$/,'')} BPM · ${sessionTimeSignature}`;
    }

    recordMetronomeToggle
      ?.classList.toggle(
        'active',
        recordingMetronomeEnabled
      );
    recordMetronomeToggle
      ?.setAttribute(
        'aria-pressed',
        recordingMetronomeEnabled
          ? 'true'
          : 'false'
      );

    if (recordMetronomeToggle) {
      recordMetronomeToggle.textContent =
        recordingMetronomeEnabled
          ? 'ON'
          : 'OFF';
    }

    recordPunchToggle
      ?.classList.toggle(
        'active',
        recordingPunchEnabled
      );
    recordPunchToggle
      ?.setAttribute(
        'aria-pressed',
        recordingPunchEnabled
          ? 'true'
          : 'false'
      );

    if (recordPunchToggle) {
      recordPunchToggle.textContent =
        recordingPunchEnabled
          ? 'ON'
          : 'OFF';
    }

    const validPunch =
      recordingPunchEnd >
      recordingPunchStart;

    if (recordPunchRange) {
      recordPunchRange.textContent =
        validPunch
          ? `${formatTime(recordingPunchStart)} → ${formatTime(recordingPunchEnd)}`
          : 'No punch range';
    }

    if (inspectorRecordingState) {
      inspectorRecordingState.textContent =
        recordingCountInActive
          ? 'Count-in'
          : recordingActive
            ? 'Recording'
            : validPunch &&
              recordingPunchEnabled
              ? 'Punch ready'
              : 'Ready';
    }

    const armed =
      Boolean(
        armedRecordingStem()
      );
    const externalLiveArmed =
      Boolean(
        window.STONEFELLOW_LIVE_RECORDING_V87
          ?.hasArmedSources?.()
      );
    const hasRecordingSource =
      armed || externalLiveArmed;
    const recordingSourceTitle =
      armed
        ? 'Record armed track'
        : externalLiveArmed
          ? 'Record armed live outputs'
          : 'Arm a track or live output before recording';

    if (inspectorRecordButton) {
      inspectorRecordButton.disabled =
        recordingActive ||
        recordingStopping ||
        Boolean(recordingPendingSave) ||
        !hasRecordingSource;

      inspectorRecordButton.title =
        recordingSourceTitle;
    }

    if (studioRecordButton) {
      const canActAsStop =
        recordingActive ||
        recordingCountInActive;

      studioRecordButton.disabled =
        recordingStopping ||
        Boolean(recordingPendingSave) ||
        (
          !canActAsStop &&
          !hasRecordingSource
        );

      if (
        !canActAsStop &&
        !recordingPendingSave
      ) {
        studioRecordButton.title =
          recordingSourceTitle;
      }
    }

    if (inspectorStopRecordButton) {
      inspectorStopRecordButton.disabled =
        !recordingActive &&
        !recordingCountInActive;
    }
  }

  function restoreRecordingSettings() {
    try {
      const raw =
        localStorage.getItem(
          recordingSettingsStorageKey
        );

      if (raw) {
        const saved =
          JSON.parse(raw);

        recordingCountInBars =
          [0,1,2,4].includes(
            Number(
              saved?.countInBars || 0
            )
          )
            ? Number(
                saved.countInBars
              )
            : 0;

        recordingMetronomeEnabled =
          Boolean(
            saved?.metronome
          );

        recordingPunchEnabled =
          Boolean(
            saved?.punch
          );

        recordingPunchStart =
          Math.max(
            0,
            Number(
              saved?.punchStart || 0
            )
          );

        recordingPunchEnd =
          Math.max(
            0,
            Number(
              saved?.punchEnd || 0
            )
          );
      }
    } catch (error) {}

    updateRecordingSettingsUi();
  }

  function playMetronomeClick(
    accent = false
  ) {
    if (window.StonefellowMetronomeV91?.playLegacyClick) {
      window.StonefellowMetronomeV91.playLegacyClick(accent);
      return;
    }

    if (!context) {
      return;
    }

    try {
      const oscillator =
        context.createOscillator();
      const gain =
        context.createGain();
      const now =
        context.currentTime;

      oscillator.type = 'square';
      oscillator.frequency
        .setValueAtTime(
          accent
            ? 1320
            : 920,
          now
        );

      gain.gain
        .setValueAtTime(
          accent
            ? .18
            : .11,
          now
        );
      gain.gain
        .exponentialRampToValueAtTime(
          .0001,
          now + .055
        );

      oscillator.connect(gain);
      gain.connect(
        context.destination
      );

      oscillator.start(now);
      oscillator.stop(
        now + .06
      );

      oscillator.addEventListener(
        'ended',
        () => {
          try {
            oscillator.disconnect();
            gain.disconnect();
          } catch (error) {}
        },
        {
          once:true
        }
      );
    } catch (error) {}
  }

  function syncMetronomeAt(timelineNow) {
    if (
      !recordingMetronomeEnabled ||
      recordingCountInActive ||
      !context
    ) {
      return;
    }

    const beatSeconds =
      recordingBeatSeconds();
    const beatIndex =
      Math.floor(
        Math.max(0,Number(timelineNow || 0)) /
        beatSeconds
      );

    if (beatIndex === metronomeLastBeat) {
      return;
    }

    metronomeLastBeat = beatIndex;

    const {
      numerator
    } = timelineSignatureParts();

    playMetronomeClick(
      beatIndex % numerator === 0
    );
  }

  async function runRecordingCountIn() {
    const bars =
      Math.max(
        0,
        Number(
          recordingCountInBars || 0
        )
      );

    if (!bars) {
      return true;
    }

    const {
      numerator
    } = timelineSignatureParts();
    const beats =
      bars *
      numerator;
    const beatMs =
      recordingBeatSeconds() *
      1000;

    recordingCountInActive = true;
    recordingCountInCancel = false;
    updateRecordingSettingsUi();

    for (
      let beat = 0;
      beat < beats;
      beat++
    ) {
      if (recordingCountInCancel) {
        recordingCountInActive =
          false;
        updateRecordingSettingsUi();
        setRecordingStatus(
          'COUNT-IN CANCELLED'
        );
        return false;
      }

      const beatInBar =
        beat %
        numerator;
      const remaining =
        beats - beat;

      playMetronomeClick(
        beatInBar === 0
      );

      setRecordingStatus(
        `COUNT ${remaining}`,
        'ready'
      );

      if (inspectorRecordingState) {
        inspectorRecordingState.textContent =
          `Count-in ${remaining}`;
      }

      updateRecordingRegion(
        recordingStartTimeline
      );

      await new Promise(
        resolve =>
          window.setTimeout(
            resolve,
            beatMs
          )
      );
    }

    recordingCountInActive = false;
    updateRecordingSettingsUi();

    return !recordingCountInCancel;
  }

  function updateRecordingRegion(
    currentTime = globalPosition()
  ) {
    if (!recordingRegion) {
      return;
    }

    if (
      !recordingActive &&
      !recordingCountInActive
    ) {
      recordingRegion.hidden = true;
      return;
    }

    const start =
      Math.max(
        0,
        Number(
          recordingStartTimeline || 0
        )
      );
    const now =
      Math.max(
        start,
        Number(
          currentTime || start
        )
      );
    const visibleTimeline =
      Math.max(
        duration,
        .01
      );
    const visibleNow =
      Math.min(
        visibleTimeline,
        now
      );

    recordingRegion.hidden = false;
    recordingRegion.style.left =
      `${Math.min(
        100,
        (
          start /
          visibleTimeline
        ) *
        100
      )}%`;
    recordingRegion.style.width =
      `${Math.max(
        .15,
        Math.min(
          100,
          (
            Math.max(
              0,
              visibleNow -
              start
            ) /
            visibleTimeline
          ) *
          100
        )
      )}%`;

    const label =
      recordingRegion.querySelector(
        'span'
      );

    if (label) {
      label.textContent =
        recordingCountInActive
          ? 'COUNT'
          : `REC ${formatTime(
              Math.max(
                0,
                (
                  performance.now() -
                  recordingStartWallTime
                ) /
                1000
              )
            )}`;
    }
  }

  function setPunchRangeFromCurrentContext() {
    let start = 0;
    let end = 0;

    if (
      loopEnd >
      loopStart
    ) {
      start = loopStart;
      end = loopEnd;
    } else {
      const selected =
        selectedArrangementData();

      if (
        selected?.kind ===
        'stem'
      ) {
        start =
          selected.clip.timelineStart;
        end =
          selected.clip.timelineStart +
          selected.clip.timelineLength;
      }
    }

    if (end <= start) {
      if (recordPunchRange) {
        recordPunchRange.textContent =
          'Set a loop or select a clip first';
      }
      return false;
    }

    recordingPunchStart =
      Math.max(
        0,
        start
      );
    recordingPunchEnd =
      Math.max(
        recordingPunchStart +
        .05,
        end
      );
    recordingPunchEnabled =
      true;

    saveRecordingSettings();
    updateRecordingSettingsUi();

    return true;
  }

  function startRecordingMeterLoop() {
    cancelAnimationFrame(
      recordingMeterFrame
    );

    const tick = () => {
      if (
        recordingInputAnalyser &&
        recordingMeterData
      ) {
        recordingInputAnalyser
          .getByteTimeDomainData(
            recordingMeterData
          );

        let sum = 0;
        let peak = 0;

        for (
          let index = 0;
          index < recordingMeterData.length;
          index++
        ) {
          const sample =
            (
              recordingMeterData[index] -
              128
            ) /
            128;

          sum +=
            sample *
            sample;

          peak = Math.max(
            peak,
            Math.abs(sample)
          );
        }

        const rms = Math.sqrt(
          sum /
          Math.max(
            1,
            recordingMeterData.length
          )
        );

        const level = Math.max(
          rms * 3.4,
          peak * 0.82
        );

        studioInputMeter
          ?.style.setProperty(
            '--input-level',
            `${Math.max(
              1,
              Math.min(
                100,
                level * 100
              )
            ).toFixed(1)}%`
          );

        if (recordingActive) {
          recordingSignalPeak =
            Math.max(
              recordingSignalPeak,
              peak
            );
        }

        if (peak >= .985) {
          inputClipLatched = true;
        }

        studioInputMeter
          ?.classList.toggle(
            'clipping',
            inputClipLatched
          );
      }

      if (
        recordingActive &&
        recordingUploadError &&
        !recordingStopping
      ) {
        setRecordingStatus(
          'UPLOAD ERROR',
          'error'
        );

        stopStudioRecording();
      }

      if (recordingActive) {
        const elapsed =
          Math.max(
            0,
            (
              performance.now() -
              recordingStartWallTime
            ) /
            1000
          );
        const timelineNow =
          globalPosition();

        setRecordingStatus(
          `REC ${formatTime(elapsed)} · ${formatRecordingBytes(recordingCapturedBytes)}`,
          'recording'
        );

        updateRecordingRegion(
          timelineNow
        );

        drawLiveRecordingVisual(
          timelineNow
        );

        if (
          recordingMetronomeEnabled &&
          !playing
        ) {
          syncMetronomeAt(timelineNow);
        }

        if (
          recordingPunchEnabled &&
          recordingPunchEnd >
            recordingPunchStart &&
          timelineNow >=
            recordingPunchEnd &&
          !recordingStopping
        ) {
          stopStudioRecording();
        }
      } else if (
        !recordingCountInActive
      ) {
        updateRecordingRegion();
      }

      recordingMeterFrame =
        requestAnimationFrame(
          tick
        );
    };

    recordingMeterFrame =
      requestAnimationFrame(tick);
  }

  function isFocusriteAudioInput(device) {
    const label = String(
      device?.label || ''
    ).toLowerCase();

    return /focusrite|scarlett|clarett|vocaster|saffire/.test(
      label
    );
  }

  function recordingInputLabel(
    device,
    index = 0
  ) {
    const raw = String(
      device?.label || ''
    ).trim();

    if (!raw) {
      return `Audio Input ${index + 1}`;
    }

    return isFocusriteAudioInput(device)
      ? `FOCUSRITE · ${raw}`
      : raw;
  }

  function populateTrackInputSelectors(
    devices,
    defaultDeviceId = ''
  ) {
    const validIds =
      new Set(
        devices.map(device =>
          String(
            device.deviceId || ''
          )
        )
      );

    stems.forEach(
      (stem,index) => {
        const select =
          stem.inputSelect;

        if (!select) {
          return;
        }

        const requested =
          String(
            stem.recordingInputDeviceId ||
            select.value ||
            ''
          );

        select.innerHTML = '';

        if (!devices.length) {
          const option =
            document.createElement(
              'option'
            );

          option.value = '';
          option.textContent =
            'CONNECT INPUT…';

          select.appendChild(
            option
          );

          return;
        }

        devices.forEach(
          (device,deviceIndex) => {
            const option =
              document.createElement(
                'option'
              );

            option.value =
              String(
                device.deviceId || ''
              );

            option.textContent =
              recordingInputLabel(
                device,
                deviceIndex
              );

            option.dataset.deviceLabel =
              String(
                device.label || ''
              );

            option.dataset.focusrite =
              isFocusriteAudioInput(
                device
              )
                ? '1'
                : '0';

            select.appendChild(
              option
            );
          }
        );

        const preferred =
          (
            requested &&
            validIds.has(requested)
          )
            ? requested
            : (
                defaultDeviceId &&
                validIds.has(
                  String(defaultDeviceId)
                )
              )
                ? String(defaultDeviceId)
                : String(
                    devices[0]
                      ?.deviceId ||
                    ''
                  );

        select.value =
          preferred;

        stem.recordingInputDeviceId =
          preferred;

        select.title =
          select
            .selectedOptions?.[0]
            ?.textContent ||
          `Input ${index + 1}`;
      }
    );

    updateTrackInspector();
  }

  async function refreshRecordingDevices(
    preserveValue = true,
    preferredDeviceId = '',
    preferFocusrite = false
  ) {
    if (
      !navigator.mediaDevices
        ?.enumerateDevices
    ) {
      setRecordingStatus(
        'INPUT UNSUPPORTED',
        'error'
      );

      populateTrackInputSelectors(
        [],
        ''
      );

      return [];
    }

    const current =
      preserveValue
        ? String(
            studioAudioInput?.value ||
            ''
          )
        : '';

    const saved = (() => {
      try {
        return localStorage.getItem(
          recordingInputStorageKey
        ) || '';
      } catch (error) {
        return '';
      }
    })();

    const seen = new Set();

    const devices =
      (
        await navigator.mediaDevices
          .enumerateDevices()
      )
        .filter(device =>
          device.kind ===
          'audioinput'
        )
        .filter(device => {
          const key =
            String(
              device.deviceId ||
              `${device.groupId}:${device.label}`
            );

          if (seen.has(key)) {
            return false;
          }

          seen.add(key);
          return true;
        });

    const focusrite =
      devices.find(
        isFocusriteAudioInput
      );

    let preferred = '';

    if (studioAudioInput) {
      studioAudioInput.innerHTML =
        devices.length
          ? ''
          : '<option value="">No browser audio inputs found</option>';

      devices.forEach(
        (device,index) => {
          const option =
            document.createElement(
              'option'
            );

          option.value =
            device.deviceId;

          option.textContent =
            recordingInputLabel(
              device,
              index
            );

          option.dataset.deviceLabel =
            String(
              device.label || ''
            );

          option.dataset.focusrite =
            isFocusriteAudioInput(device)
              ? '1'
              : '0';

          studioAudioInput
            .appendChild(
              option
            );
        }
      );

      const candidates = [
        String(
          preferredDeviceId || ''
        ),
        preferFocusrite
          ? String(
              focusrite?.deviceId || ''
            )
          : '',
        saved,
        current,
        !preferFocusrite
          ? String(
              focusrite?.deviceId || ''
            )
          : '',
        String(
          devices.find(
            device =>
              device.deviceId ===
              'default'
          )?.deviceId ||
          ''
        ),
        String(
          devices[0]?.deviceId || ''
        )
      ];

      preferred =
        candidates.find(value =>
          value &&
          devices.some(
            device =>
              device.deviceId === value
          )
        ) || '';

      studioAudioInput.value =
        preferred;
    }

    populateTrackInputSelectors(
      devices,
      preferred
    );

    return devices;
  }


  function stopPermissionProbe(stream) {
    stream
      ?.getTracks?.()
      ?.forEach(track => {
        try {
          track.stop();
        } catch (error) {}
      });
  }

  async function microphonePermissionState() {
    if (
      !navigator.permissions
        ?.query
    ) {
      return 'unknown';
    }

    try {
      const status =
        await navigator.permissions
          .query({
            name:'microphone'
          });

      return String(
        status?.state ||
        'unknown'
      );
    } catch (error) {
      return 'unknown';
    }
  }

  function audioInputErrorMessage(error) {
    const name =
      String(
        error?.name ||
        ''
      );

    const raw =
      String(
        error?.message ||
        ''
      ).trim();

    if (!window.isSecureContext) {
      return 'Audio input is blocked because this Studio page is not running in a secure HTTPS context.';
    }

    if (
      name === 'NotAllowedError' ||
      name === 'SecurityError' ||
      /permission denied|not allowed|denied permission/i
        .test(raw)
    ) {
      return 'The browser refused microphone/audio-input access. If the site setting already says Allow, check Windows Privacy & security → Microphone and make sure desktop/browser microphone access is enabled, then retry.';
    }

    if (
      name === 'NotFoundError' ||
      name === 'DevicesNotFoundError'
    ) {
      return 'No audio input was found. Reconnect the Focusrite and confirm it appears in Windows sound input settings.';
    }

    if (
      name === 'NotReadableError' ||
      name === 'TrackStartError' ||
      /could not start audio source|device.*busy/i
        .test(raw)
    ) {
      return 'The selected audio input is present but unavailable. Close any app using it exclusively, then rescan.';
    }

    if (
      name === 'OverconstrainedError' ||
      name === 'ConstraintNotSatisfiedError'
    ) {
      return 'The selected audio input is no longer available. Rescan the connected audio devices.';
    }

    if (
      name === 'AbortError'
    ) {
      return 'Audio input connection was interrupted. Reconnect the interface and retry.';
    }

    return raw ||
      'Stonefellow could not open the selected audio input.';
  }

  function setAudioConnectButtonState(
    state,
    label = ''
  ) {
    if (!studioInputAccess) {
      return;
    }

    studioInputAccess.classList.remove(
      'ready',
      'blocked',
      'error'
    );

    if (state) {
      studioInputAccess.classList.add(
        state
      );
    }

    if (label) {
      studioInputAccess.textContent =
        label;
    }
  }

  function showAudioPermissionHelp(
    message,
    detail = ''
  ) {
    if (audioPermissionMessage) {
      audioPermissionMessage.textContent =
        String(
          message ||
          'Audio input permission is required.'
        );
    }

    if (audioPermissionDetail) {
      audioPermissionDetail.textContent =
        String(
          detail ||
          'Allow Microphone access for this site, then retry the audio connection.'
        );
    }

    openModal(
      audioPermissionDialog
    );
  }

  function microphonePolicyAllows() {
    try {
      const policy =
        document.permissionsPolicy ||
        document.featurePolicy;

      if (
        policy?.allowsFeature
      ) {
        return policy.allowsFeature(
          'microphone'
        );
      }
    } catch (error) {}

    return true;
  }

  async function assertAudioPermissionCanPrompt() {
    if (!window.isSecureContext) {
      const message =
        'Audio input requires HTTPS.';

      setAudioConnectButtonState(
        'blocked',
        'Audio Blocked'
      );

      showAudioPermissionHelp(
        message,
        'This page is not in a secure browser context. Open the HTTPS version of the site before connecting the Focusrite.'
      );

      const error =
        new Error(
          'Audio input requires a secure HTTPS browser session.'
        );

      error.name =
        'SecurityError';

      throw error;
    }

    if (!microphonePolicyAllows()) {
      const message =
        'This page is blocked from using microphone/audio input by the browser Permissions Policy.';

      setAudioConnectButtonState(
        'blocked',
        'Audio Blocked'
      );

      showAudioPermissionHelp(
        message,
        'Stonefellow now sends microphone=(self). If this Studio is embedded in another page, that iframe must also include allow="microphone".'
      );

      const error =
        new Error(message);

      error.name =
        'SecurityError';

      throw error;
    }

    /*
     * Important: navigator.permissions.query({name:"microphone"}) is only
     * advisory here. Some Chrome/Edge/Windows combinations can report
     * "denied" even while the site control shows Allow. The actual
     * getUserMedia() request is the authoritative test, so never block the
     * user based only on the Permissions API result.
     */
    return microphonePermissionState();
  }


  async function requestRecordingPermissionProbe() {
    await assertAudioPermissionCanPrompt();

    setRecordingStatus(
      'SCANNING INPUTS…'
    );

    setAudioConnectButtonState(
      '',
      'Connecting…'
    );

    try {
      return await navigator.mediaDevices
        .getUserMedia({
          audio:{
            echoCancellation:false,
            noiseSuppression:false,
            autoGainControl:false
          },
          video:false
        });
    } catch (error) {
      const message =
        audioInputErrorMessage(
          error
        );

      if (
        error?.name ===
          'NotAllowedError' ||
        error?.name ===
          'SecurityError' ||
        /permission|denied|not allowed/i
          .test(
            String(
              error?.message ||
              ''
            )
          )
      ) {
        setAudioConnectButtonState(
          'blocked',
          'Audio Blocked'
        );

        showAudioPermissionHelp(
          'Stonefellow cannot access audio inputs.',
          message
        );
      } else {
        setAudioConnectButtonState(
          'error',
          'Retry Audio'
        );
      }

      const friendly =
        new Error(message);

      friendly.name =
        error?.name ||
        'AudioInputError';

      throw friendly;
    }
  }

  async function openRecordingDevice(
    deviceId = ''
  ) {
    const cleanId =
      String(deviceId || '');

    const base = {
      echoCancellation:false,
      noiseSuppression:false,
      autoGainControl:false,
      channelCount:{
        ideal:2
      }
    };

    if (!cleanId) {
      return navigator.mediaDevices
        .getUserMedia({
          audio:base,
          video:false
        });
    }

    try {
      return await navigator.mediaDevices
        .getUserMedia({
          audio:{
            ...base,
            deviceId:{
              exact:cleanId
            }
          },
          video:false
        });
    } catch (exactError) {
      try {
        return await navigator.mediaDevices
          .getUserMedia({
            audio:{
              ...base,
              deviceId:{
                ideal:cleanId
              }
            },
            video:false
          });
      } catch (idealError) {
        if (
          idealError?.name ===
            'NotAllowedError' ||
          idealError?.name ===
            'SecurityError'
        ) {
          const permissionError =
            new Error(
              audioInputErrorMessage(
                idealError
              )
            );

          permissionError.name =
            idealError.name;

          throw permissionError;
        }

        const selected =
          studioAudioInput
            ?.selectedOptions?.[0];

        const name =
          String(
            selected?.dataset.deviceLabel ||
            selected?.textContent ||
            'selected audio input'
          ).replace(
            /^FOCUSRITE\s*·\s*/i,
            ''
          );

        const unavailable =
          new Error(
            `${name} is visible to the browser but could not be opened. Close other apps using the input and reconnect it.`
          );

        unavailable.name =
          idealError?.name ||
          'NotReadableError';

        throw unavailable;
      }
    }
  }

  async function connectRecordingInput(
    requestedDeviceId = ''
  ) {
    if (recordingActive) {
      throw new Error(
        'Stop recording before changing the audio input.'
      );
    }

    if (
      !navigator.mediaDevices
        ?.getUserMedia
    ) {
      throw new Error(
        'Audio input requires a secure HTTPS browser session with media-device support.'
      );
    }

    setRecordingStatus(
      'CONNECTING…'
    );

    const explicitDeviceId =
      String(
        requestedDeviceId || ''
      );

    let permissionProbe = null;
    let deviceId =
      explicitDeviceId;

    try {
      /*
       * Chrome/Edge hide hardware names until microphone permission has
       * been granted. CONNECT therefore opens a short default-input probe
       * first, refreshes the now-labeled device list, and automatically
       * prefers a Focusrite/Scarlett/Clarett/Vocaster input when present.
       */
      if (!explicitDeviceId) {
        permissionProbe =
          await requestRecordingPermissionProbe();

        const devices =
          await refreshRecordingDevices(
            false,
            '',
            true
          );

        deviceId =
          String(
            studioAudioInput?.value ||
            ''
          );

        if (!devices.length) {
          throw new Error(
            'No browser audio inputs were found after permission was granted.'
          );
        }
      }

      stopPermissionProbe(
        permissionProbe
      );
      permissionProbe = null;

      const stream =
        await openRecordingDevice(
          deviceId
        );

      ensureAudioGraph();

      if (!context) {
        stopPermissionProbe(stream);

        throw new Error(
          'Web Audio is unavailable in this browser.'
        );
      }

      if (
        context.state ===
        'suspended'
      ) {
        await context.resume();
      }

      disconnectRecordingInputGraph(
        true
      );

      recordingStream = stream;

      const track =
        stream.getAudioTracks()[0];

      const settings =
        track?.getSettings?.() || {};

      recordingSampleRate =
        Math.round(
          Number(
            context.sampleRate ||
            settings.sampleRate ||
            48000
          )
        );

      recordingChannelCount =
        Math.max(
          1,
          Math.min(
            2,
            Number(
              settings.channelCount ||
              2
            )
          )
        );

      recordingInputSource =
        context.createMediaStreamSource(
          stream
        );

      try {
        recordingInputSource.channelCount =
          recordingChannelCount;
        recordingInputSource.channelCountMode =
          'explicit';
        recordingInputSource.channelInterpretation =
          'discrete';
      } catch (error) {
        // Some browser engines expose these as read-only. Capture can continue.
      }

      const boundRecordingStream =
        stream;

      track?.addEventListener(
        'ended',
        () => {
          if (
            recordingStream !==
            boundRecordingStream
          ) {
            return;
          }

          setRecordingStatus(
            'INPUT DISCONNECTED',
            'error'
          );

          if (
            recordingActive &&
            !recordingStopping
          ) {
            stopStudioRecording();
          }
        }
      );

      track?.addEventListener(
        'mute',
        () => {
          if (
            recordingStream !==
            boundRecordingStream
          ) {
            return;
          }

          setRecordingStatus(
            recordingActive
              ? 'INPUT SIGNAL LOST'
              : 'INPUT MUTED',
            'error'
          );
        }
      );

      track?.addEventListener(
        'unmute',
        () => {
          if (
            recordingStream !==
            boundRecordingStream
          ) {
            return;
          }

          const armed =
            armedRecordingStem();

          setRecordingStatus(
            recordingActive
              ? 'REC'
              : (
                  armed
                    ? `ARMED · ${armed.name || armed.label}`
                    : 'READY · ARM A TRACK'
                ),
            recordingActive
              ? 'recording'
              : 'ready'
          );
        }
      );

      recordingInputAnalyser =
        context.createAnalyser();
      recordingInputAnalyser.fftSize =
        512;
      recordingInputAnalyser
        .smoothingTimeConstant =
        0.66;

      recordingMeterData =
        new Uint8Array(
          recordingInputAnalyser
            .fftSize
        );

      recordingMeterSink =
        context.createGain();
      recordingMeterSink.gain.value =
        0;

      recordingInputSource.connect(
        recordingInputAnalyser
      );
      recordingInputAnalyser.connect(
        recordingMeterSink
      );
      recordingMeterSink.connect(
        context.destination
      );

      recordingMonitorGain =
        context.createGain();
      recordingMonitorGain.gain.value =
        recordingMonitorEnabled
          ? 1
          : 0;

      recordingInputSource.connect(
        recordingMonitorGain
      );

      recordingMonitorGain.connect(
        busInput
      );

      updateRecordingMonitor();
      startRecordingMeterLoop();

      const activeDeviceId =
        String(
          track?.getSettings?.()
            ?.deviceId ||
          deviceId ||
          ''
        );

      await refreshRecordingDevices(
        false,
        activeDeviceId,
        false
      );

      if (
        studioAudioInput &&
        activeDeviceId
      ) {
        const exists =
          [
            ...studioAudioInput.options
          ].some(
            option =>
              option.value ===
              activeDeviceId
          );

        if (exists) {
          studioAudioInput.value =
            activeDeviceId;
        }
      }

      try {
        if (studioAudioInput?.value) {
          localStorage.setItem(
            recordingInputStorageKey,
            studioAudioInput.value
          );
        }
      } catch (error) {}

      const actualLabel =
        String(
          track?.label ||
          recordingDeviceLabel() ||
          'Audio Input'
        ).trim();

      const actualIsFocusrite =
        /focusrite|scarlett|clarett|vocaster|saffire/i
          .test(actualLabel);

      setAudioConnectButtonState(
        'ready',
        'Rescan Audio'
      );

      const armed =
        stemById(
          recordingArmedStemId
        );

      setRecordingStatus(
        armed
          ? `ARMED · ${armed.name || armed.label}`
          : (
              actualIsFocusrite
                ? 'FOCUSRITE READY'
                : `READY · ${actualLabel}`
            ),
        'ready'
      );

      return stream;
    } catch (error) {
      const message =
        audioInputErrorMessage(
          error
        );

      const blocked =
        error?.name ===
          'NotAllowedError' ||
        error?.name ===
          'SecurityError' ||
        /permission|denied|not allowed/i
          .test(
            String(
              error?.message ||
              ''
            )
          );

      setRecordingStatus(
        blocked
          ? 'MIC BLOCKED'
          : message,
        'error'
      );

      if (blocked) {
        setAudioConnectButtonState(
          'blocked',
          'Audio Blocked'
        );

        if (
          audioPermissionDialog
            ?.hidden !== false
        ) {
          showAudioPermissionHelp(
            'Stonefellow cannot access the Focusrite/audio input.',
            message
          );
        }
      } else {
        setAudioConnectButtonState(
          'error',
          'Retry Audio'
        );
      }

      const friendly =
        new Error(message);

      friendly.name =
        error?.name ||
        'AudioInputError';

      throw friendly;
    } finally {
      stopPermissionProbe(
        permissionProbe
      );
    }
  }


  function pcm16FromAudioBuffer(
    inputBuffer,
    channels
  ) {
    const frames =
      inputBuffer.length;
    const sourceChannels =
      inputBuffer.numberOfChannels;

    const buffer =
      new ArrayBuffer(
        frames *
        channels *
        2
      );

    const view =
      new DataView(buffer);

    const data = [];

    for (
      let channel = 0;
      channel < channels;
      channel++
    ) {
      data.push(
        inputBuffer.getChannelData(
          Math.min(
            channel,
            Math.max(
              0,
              sourceChannels - 1
            )
          )
        )
      );
    }

    let offset = 0;

    for (
      let frame = 0;
      frame < frames;
      frame++
    ) {
      for (
        let channel = 0;
        channel < channels;
        channel++
      ) {
        const sample =
          Math.max(
            -1,
            Math.min(
              1,
              Number(
                data[channel][frame] ||
                0
              )
            )
          );

        const value =
          sample < 0
            ? Math.round(
                sample *
                0x8000
              )
            : Math.round(
                sample *
                0x7fff
              );

        view.setInt16(
          offset,
          value,
          true
        );

        offset += 2;
      }
    }

    return new Uint8Array(
      buffer
    );
  }

  function enqueueRecordingChunk(
    bytes
  ) {
    if (
      !bytes?.byteLength ||
      !recordingId
    ) {
      return;
    }

    const chunkIndex =
      recordingChunkIndex++;

    const blob =
      new Blob(
        [bytes],
        {
          type:
            'application/octet-stream'
        }
      );

    recordingUploadChain =
      recordingUploadChain.then(
        async () => {
          if (
            recordingUploadError
          ) {
            return;
          }

          const form =
            new FormData();

          form.append(
            'csrf_token',
            String(cfg.csrf || '')
          );
          form.append(
            'action',
            'recording_chunk'
          );
          form.append(
            'track_id',
            String(trackId)
          );
          form.append(
            'recording_id',
            recordingId
          );
          form.append(
            'chunk_index',
            String(chunkIndex)
          );
          form.append(
            'pcm',
            blob,
            `capture-${chunkIndex}.pcm`
          );

          try {
            const response =
              await fetch(
                projectEndpoint,
                {
                  method:'POST',
                  credentials:
                    'same-origin',
                  body:form
                }
              );

            const payload =
              await response
                .json()
                .catch(() => ({
                  ok:false,
                  error:
                    'Invalid recording response.'
                }));

            if (
              !response.ok ||
              !payload.ok
            ) {
              throw new Error(
                payload.error ||
                `Recording upload failed (${response.status}).`
              );
            }
          } catch (error) {
            recordingUploadError =
              error instanceof Error
                ? error
                : new Error(
                    'Recording upload failed.'
                  );
          }
        }
      );
  }

  function flushRecordingPcm(
    force = false
  ) {
    if (
      !recordingPendingByteLength ||
      (
        !force &&
        recordingPendingByteLength <
          recordingChunkTargetBytes
      )
    ) {
      return;
    }

    const merged =
      new Uint8Array(
        recordingPendingByteLength
      );

    let offset = 0;

    recordingPendingBytes
      .forEach(bytes => {
        merged.set(
          bytes,
          offset
        );
        offset +=
          bytes.byteLength;
      });

    recordingPendingBytes = [];
    recordingPendingByteLength = 0;

    enqueueRecordingChunk(
      merged
    );
  }

  function stopRecordingProcessor() {
    if (
      recordingInputSource &&
      recordingProcessor
    ) {
      try {
        recordingInputSource.disconnect(
          recordingProcessor
        );
      } catch (error) {}
    }

    if (recordingProcessor) {
      recordingProcessor
        .onaudioprocess = null;

      try {
        recordingProcessor.disconnect();
      } catch (error) {}
    }

    if (recordingProcessorSink) {
      try {
        recordingProcessorSink
          .disconnect();
      } catch (error) {}
    }

    recordingProcessor = null;
    recordingProcessorSink = null;
  }

  async function cancelServerRecording() {
    if (!recordingId) {
      return;
    }

    const id =
      recordingId;

    recordingId = '';

    try {
      await studioProjectRequest(
        'recording_cancel',
        {
          track_id:trackId,
          recording_id:id
        }
      );
    } catch (error) {
      console.warn(
        'Recording cleanup failed.',
        error
      );
    }
  }

  async function startStudioRecording() {
    if (recordingCountInActive) {
      recordingCountInCancel =
        true;
      return;
    }

    if (
      recordingActive ||
      recordingStopping ||
      recordingPendingSave
    ) {
      return;
    }

    const externalLiveArmed = Boolean(
      window.STONEFELLOW_LIVE_RECORDING_V87
        ?.hasArmedSources?.()
    );
    const armed = recordingArmedStemId > 0
      ? requireArmedRecordingTrack()
      : null;

    if (!armed && !externalLiveArmed) {
      requireArmedRecordingTrack();
      return;
    }

    if (!armed && externalLiveArmed) {
      studioRecordButton && (studioRecordButton.disabled = true);
      setRecordingTransportLock(true);
      try {
        ensureAudioGraph();
        if (!context) throw new Error('Web Audio recording is not available.');
        if (context.state === 'suspended') await context.resume();
        recordingStartTimeline = Math.max(0,globalPosition());
        recordingOriginalDuration = duration;
        const countInComplete = await runRecordingCountIn();
        if (!countInComplete) { setRecordingTransportLock(false); return; }
        recordingLiveOnlyMode = true;
        recordingStopping = false;
        await window.STONEFELLOW_LIVE_RECORDING_V87.startAll(recordingStartTimeline);
        recordingStartWallTime = performance.now();
        recordingActive = true;
        studioRecordButton?.classList.add('active');
        studioRecordButton?.setAttribute('aria-pressed','true');
        if (studioRecordButton) { studioRecordButton.disabled=false; studioRecordButton.title='Stop live recording'; }
        setRecordingStatus('LIVE REC 0:00','recording');
        recordingStartedTransport = !playing;
        if (!playing) await playAll();
      } catch (error) {
        recordingLiveOnlyMode = false;
        recordingActive = false;
        recordingStopping = false;
        setRecordingTransportLock(false);
        if (studioRecordButton) studioRecordButton.disabled=false;
        setRecordingStatus(error?.message || 'LIVE RECORD FAILED','error');
      }
      return;
    }

    studioRecordButton &&
      (studioRecordButton.disabled = true);

    setRecordingTransportLock(
      true
    );

    try {
      resetRecordingCaptureState();
      removeLiveRecordingVisual();

      recordingOriginalDuration =
        duration;

      const desiredDeviceId =
        selectedRecordingDeviceId();

      if (
        !recordingStream ||
        (
          desiredDeviceId &&
          activeRecordingDeviceId() !==
            desiredDeviceId
        )
      ) {
        await connectRecordingInput(
          desiredDeviceId
        );
      }

      ensureAudioGraph();

      if (!context) {
        throw new Error(
          'Web Audio recording is not available.'
        );
      }

      if (
        context.state ===
        'suspended'
      ) {
        await context.resume();
      }

      const processorFactory =
        context.createScriptProcessor ||
        context.createJavaScriptNode;

      if (!processorFactory) {
        throw new Error(
          'This browser cannot provide PCM recording buffers.'
        );
      }

      recordingSampleRate =
        Math.round(
          Number(
            context.sampleRate ||
            48000
          )
        );

      const inputTrack =
        recordingStream
          .getAudioTracks()[0];

      if (
        !inputTrack ||
        inputTrack.readyState !== 'live' ||
        inputTrack.enabled === false
      ) {
        throw new Error(
          'The selected audio input is not live.'
        );
      }

      const settings =
        inputTrack.getSettings?.() || {};

      recordingChannelCount =
        Math.max(
          1,
          Math.min(
            2,
            Number(
              settings.channelCount ||
              recordingChannelCount ||
              2
            )
          )
        );

      if (
        recordingPunchEnabled &&
        recordingPunchEnd >
          recordingPunchStart
      ) {
        await seekAllSafely(
          recordingPunchStart,
          false
        );

        position =
          recordingPunchStart;
      }

      recordingStartTimeline =
        Math.max(
          0,
          recordingPunchEnabled &&
          recordingPunchEnd >
            recordingPunchStart
            ? recordingPunchStart
            : globalPosition()
        );

      ensureLiveRecordingVisual();
      drawLiveRecordingVisual(
        recordingStartTimeline
      );

      inputClipLatched = false;
      metronomeLastBeat = -1;

      if (studioRecordButton) {
        studioRecordButton.disabled =
          false;
        studioRecordButton.title =
          recordingCountInBars > 0
            ? 'Cancel count-in'
            : 'Stop recording';
      }

      studioRecordButton
        ?.classList.toggle(
          'counting',
          recordingCountInBars > 0
        );

      updateRecordingRegion(
        recordingStartTimeline
      );

      const countInComplete =
        await runRecordingCountIn();

      studioRecordButton
        ?.classList.remove(
          'counting'
        );

      if (!countInComplete) {
        if (studioRecordButton) {
          studioRecordButton.disabled =
            false;
          studioRecordButton.title =
            'Record audio';
        }

        updateRecordingRegion();
        setRecordingTransportLock(
          false
        );
        return;
      }

      const trackName =
        recordingTrackName();

      const started =
        await studioProjectRequest(
          'recording_start',
          {
            track_id:trackId,
            track_name:trackName,
            start_offset:
              recordingStartTimeline,
            sample_rate:
              recordingSampleRate,
            channels:
              recordingChannelCount,
            session_tempo:
              sessionTempo,
            device_label:
              recordingDeviceLabel(),
            target_stem_id:
              recordingArmedStemId
          }
        );

      recordingId =
        String(
          started.recording_id ||
          ''
        );

      if (!recordingId) {
        throw new Error(
          'The server did not create a recording session.'
        );
      }

      recordingChunkIndex = 0;
      recordingPendingBytes = [];
      recordingPendingByteLength = 0;
      recordingUploadError = null;
      recordingUploadChain =
        Promise.resolve();

      recordingProcessor =
        processorFactory.call(
          context,
          2048,
          recordingChannelCount,
          recordingChannelCount
        );

      try {
        recordingProcessor.channelCount =
          recordingChannelCount;
        recordingProcessor.channelCountMode =
          'explicit';
        recordingProcessor.channelInterpretation =
          'discrete';
      } catch (error) {
        // Browser fallback remains valid when ScriptProcessor properties are fixed.
      }

      recordingProcessorSink =
        context.createGain();
      recordingProcessorSink
        .gain.value = 0;

      recordingInputSource.connect(
        recordingProcessor
      );

      recordingProcessor.connect(
        recordingProcessorSink
      );

      recordingProcessorSink.connect(
        context.destination
      );

      recordingProcessor
        .onaudioprocess =
        event => {
          if (
            !recordingActive ||
            recordingStopping
          ) {
            return;
          }

          appendLiveRecordingPeak(
            event.inputBuffer
          );

          const bytes =
            pcm16FromAudioBuffer(
              event.inputBuffer,
              recordingChannelCount
            );

          recordingPendingBytes.push(
            bytes
          );

          recordingPendingByteLength +=
            bytes.byteLength;
          recordingCapturedBytes +=
            bytes.byteLength;

          flushRecordingPcm(
            false
          );
        };

      recordingStopping = false;
      recordingLiveOnlyMode = false;

      // Prepare all output recorders while the microphone callback is still
      // gated off. startAll() attaches all prepared output processors in one
      // synchronous activation pass before returning.
      if (window.STONEFELLOW_LIVE_RECORDING_V87?.hasArmedSources?.()) {
        await window.STONEFELLOW_LIVE_RECORDING_V87.startAll(recordingStartTimeline);
      }

      recordingStartWallTime =
        performance.now();
      recordingActive = true;
      metronomeLastBeat = -1;

      updateRecordingSettingsUi();
      updateTrackInspector();

      studioRecordButton
        ?.classList.add('active');
      studioRecordButton
        ?.setAttribute(
          'aria-pressed',
          'true'
        );

      if (studioRecordButton) {
        studioRecordButton.disabled =
          false;
        studioRecordButton.title =
          'Stop recording';
      }

      if (studioAudioInput) {
        studioAudioInput.disabled =
          true;
      }

      stems.forEach(stem => {
        if (stem.inputSelect) {
          stem.inputSelect.disabled =
            true;
        }
      });

      if (studioInputAccess) {
        studioInputAccess.disabled =
          true;
      }

      setRecordingStatus(
        'REC 0:00',
        'recording'
      );

      recordingStartedTransport =
        !playing;

      if (!playing) {
        await playAll();
      }
    } catch (error) {
      stopRecordingProcessor();

      recordingActive = false;
      recordingStopping = false;
      recordingCountInActive = false;
      recordingCountInCancel = false;

      updateRecordingSettingsUi();
      updateRecordingRegion();
      removeLiveRecordingVisual();

      duration =
        Math.max(
          .05,
          recordingOriginalDuration ||
          duration
        );

      resizeTimelineSurface();

      studioRecordButton
        ?.classList.remove('active');
      studioRecordButton
        ?.setAttribute(
          'aria-pressed',
          'false'
        );

      if (studioRecordButton) {
        studioRecordButton.disabled =
          false;
        studioRecordButton.title =
          'Record audio';
      }

      if (studioAudioInput) {
        studioAudioInput.disabled =
          false;
      }

      stems.forEach(stem => {
        if (stem.inputSelect) {
          stem.inputSelect.disabled =
            false;
        }
      });

      if (studioInputAccess) {
        studioInputAccess.disabled =
          false;
      }

      await cancelServerRecording();

      setRecordingTransportLock(
        false
      );

      setRecordingStatus(
        error?.message ||
        'RECORD FAILED',
        'error'
      );

      console.error(
        'Studio recording failed',
        error
      );
    }
  }

  async function stopStudioRecording() {
    if (
      !recordingActive ||
      recordingStopping
    ) {
      return;
    }

    recordingStopping = true;

    if (recordingLiveOnlyMode) {
      // stopAll() synchronously detaches every live processor before its first
      // await, so all output takes share the same stop boundary.
      const liveFinalize =
        window.STONEFELLOW_LIVE_RECORDING_V87?.stopAll?.() ||
        Promise.resolve([]);
      recordingActive = false;
      if (playing) pauseAll();
      try {
        await liveFinalize;
      } finally {
        recordingStopping = false;
        recordingLiveOnlyMode = false;
        studioRecordButton?.classList.remove('active','counting');
        studioRecordButton?.setAttribute('aria-pressed','false');
        if (studioRecordButton) { studioRecordButton.disabled=false; studioRecordButton.title='Record audio'; }
        setRecordingTransportLock(false);
        setRecordingStatus('LIVE TAKE SAVED','ready');
        window.STONEFELLOW_LIVE_RECORDING_V87?.refreshAfterStandalone?.();
      }
      return;
    }

    // Halt the live-output processors and microphone processor in the same
    // synchronous turn. Network upload/finalization happens only afterwards.
    const liveFinalize =
      window.STONEFELLOW_LIVE_RECORDING_V87?.hasActiveSessions?.()
        ? window.STONEFELLOW_LIVE_RECORDING_V87.stopAll()
        : Promise.resolve([]);

    recordingActive = false;
    stopRecordingProcessor();
    flushRecordingPcm(true);

    if (playing) {
      pauseAll();
    }

    if (studioRecordButton) {
      studioRecordButton.disabled =
        true;
    }

    await liveFinalize;

    setRecordingStatus(
      'PROCESSING…',
      'recording'
    );

    try {
      await recordingUploadChain;

      if (recordingUploadError) {
        throw recordingUploadError;
      }

      if (!recordingId) {
        throw new Error(
          'The recording session was lost before it could be saved.'
        );
      }

      const status =
        await studioProjectRequest(
          'recording_status',
          {
            track_id:trackId,
            recording_id:
              recordingId
          }
        );

      const serverBytes =
        Math.max(
          0,
          Number(
            status.pcm_bytes ||
            0
          )
        );

      if (serverBytes < 2) {
        throw new Error(
          'No audio data was captured. Check the armed track input and try recording again.'
        );
      }

      if (
        recordingCapturedBytes > 0 &&
        serverBytes !==
          recordingCapturedBytes
      ) {
        throw new Error(
          `Recording upload was incomplete (${formatRecordingBytes(serverBytes)} of ${formatRecordingBytes(recordingCapturedBytes)}). Please record the take again.`
        );
      }

      showRecordingSavePrompt(
        status
      );
    } catch (error) {
      await cancelServerRecording();

      recordingPendingSave = null;

      setRecordingStatus(
        error?.message ||
        'RECORDING FAILED',
        'error'
      );

      console.error(
        'Recording stop/status failed',
        error
      );
    } finally {
      recordingStopping = false;
      recordingCountInActive = false;
      recordingCountInCancel = false;
      metronomeLastBeat = -1;

      updateRecordingSettingsUi();
      updateRecordingRegion();
      updateTrackInspector();

      studioRecordButton
        ?.classList.remove(
          'active',
          'counting'
        );
      studioRecordButton
        ?.setAttribute(
          'aria-pressed',
          'false'
        );

      if (studioRecordButton) {
        studioRecordButton.disabled =
          Boolean(
            recordingPendingSave
          );
        studioRecordButton.title =
          recordingPendingSave
            ? 'Save or discard the stopped recording first'
            : 'Record audio';
      }

      if (studioAudioInput) {
        studioAudioInput.disabled =
          false;
      }

      stems.forEach(stem => {
        if (stem.inputSelect) {
          stem.inputSelect.disabled =
            false;
        }
      });

      if (studioInputAccess) {
        studioInputAccess.disabled =
          false;
      }

      setRecordingTransportLock(
        Boolean(
          recordingPendingSave
        )
      );
    }
  }


  function mediaDurationFromFile(file) {
    return new Promise(resolve => {
      if (!file) {
        resolve(0);
        return;
      }

      const objectUrl =
        URL.createObjectURL(file);
      const audio =
        document.createElement('audio');
      let done = false;

      const finish = value => {
        if (done) return;
        done = true;

        try {
          URL.revokeObjectURL(
            objectUrl
          );
        } catch (error) {}

        audio.removeAttribute('src');
        audio.load();

        resolve(
          Number.isFinite(value)
            ? Math.max(0,value)
            : 0
        );
      };

      const timer =
        window.setTimeout(
          () => finish(0),
          5000
        );

      audio.preload = 'metadata';

      audio.addEventListener(
        'loadedmetadata',
        () => {
          window.clearTimeout(timer);
          finish(
            Number(audio.duration || 0)
          );
        },
        {once:true}
      );

      audio.addEventListener(
        'error',
        () => {
          window.clearTimeout(timer);
          finish(0);
        },
        {once:true}
      );

      audio.src = objectUrl;
    });
  }

  function setStudioImportProgress(
    percent,
    status,
    fileStatus = ''
  ) {
    const clean = Math.max(
      0,
      Math.min(
        100,
        Number(percent || 0)
      )
    );

    if (studioImportProgress) {
      studioImportProgress.style.width =
        `${clean}%`;
    }

    if (
      studioImportStatus &&
      status
    ) {
      studioImportStatus.textContent =
        String(status);
    }

    if (studioImportFileStatus) {
      studioImportFileStatus.textContent =
        String(fileStatus || '');
    }
  }

  async function uploadStudioMedia(files) {
    const selected = [
      ...(files || [])
    ];

    if (!selected.length) {
      return;
    }

    const invalid =
      selected.find(file => {
        const extension =
          String(
            file.name
              .split('.')
              .pop() || ''
          ).toLowerCase();

        return ![
          'wav',
          'mp3'
        ].includes(extension);
      });

    if (invalid) {
      alert(
        `${invalid.name}: Stem Studio currently imports WAV and MP3 media.`
      );
      return;
    }

    setStudioMainMenu(false);
    openModal(studioImportDialog);
    setStudioImportProgress(
      2,
      'Reading media metadata…'
    );

    try {
      const metadata = [];

      for (
        let index = 0;
        index < selected.length;
        index++
      ) {
        const file =
          selected[index];

        setStudioImportProgress(
          2 +
            (
              index /
              Math.max(
                1,
                selected.length
              )
            ) *
            8,
          'Reading media metadata…',
          `${index + 1} / ${selected.length} · ${file.name}`
        );

        metadata.push({
          name:file.name,
          size:file.size,
          duration:
            await mediaDurationFromFile(
              file
            )
        });
      }

      const init =
        await studioProjectRequest(
          'import_init',
          {
            track_id:trackId,
            files_json:
              JSON.stringify(metadata)
          }
        );

      const requestId =
        String(init.request_id || '');
      const chunkBytes =
        Math.max(
          1024 * 1024,
          Number(
            init.chunk_bytes ||
            8 * 1024 * 1024
          )
        );

      const totalBytes =
        selected.reduce(
          (sum,file) =>
            sum +
            Number(file.size || 0),
          0
        );

      let uploadedBytes = 0;

      for (
        let fileIndex = 0;
        fileIndex < selected.length;
        fileIndex++
      ) {
        const file =
          selected[fileIndex];
        const totalChunks =
          Math.max(
            1,
            Math.ceil(
              file.size /
              chunkBytes
            )
          );

        for (
          let chunkIndex = 0;
          chunkIndex < totalChunks;
          chunkIndex++
        ) {
          const start =
            chunkIndex *
            chunkBytes;
          const end =
            Math.min(
              file.size,
              start +
                chunkBytes
            );
          const blob =
            file.slice(
              start,
              end
            );

          const form =
            new FormData();

          form.append(
            'csrf_token',
            String(cfg.csrf || '')
          );
          form.append(
            'action',
            'import_chunk'
          );
          form.append(
            'track_id',
            String(trackId)
          );
          form.append(
            'request_id',
            requestId
          );
          form.append(
            'file_index',
            String(fileIndex)
          );
          form.append(
            'chunk_index',
            String(chunkIndex)
          );
          form.append(
            'total_chunks',
            String(totalChunks)
          );
          form.append(
            'chunk',
            blob,
            `${file.name}.part`
          );

          const response =
            await fetch(
              projectEndpoint,
              {
                method:'POST',
                credentials:'same-origin',
                body:form
              }
            );

          const payload =
            await response
              .json()
              .catch(() => ({
                ok:false,
                error:'Invalid upload response.'
              }));

          if (
            !response.ok ||
            !payload.ok
          ) {
            throw new Error(
              payload.error ||
              `Upload failed (${response.status}).`
            );
          }

          uploadedBytes +=
            blob.size;

          const percent =
            10 +
            (
              uploadedBytes /
              Math.max(
                1,
                totalBytes
              )
            ) *
            84;

          setStudioImportProgress(
            percent,
            selected.length === 1
              ? 'Importing track…'
              : 'Importing tracks…',
            `${fileIndex + 1} / ${selected.length} · ${file.name}`
          );
        }
      }

      setStudioImportProgress(
        96,
        'Building mixer tracks…',
        'Reading WAV metadata and saving project tracks'
      );

      const result =
        await studioProjectRequest(
          'import_commit',
          {
            track_id:trackId,
            request_id:requestId
          }
        );

      setStudioImportProgress(
        100,
        'Import complete',
        result.message ||
          `${selected.length} tracks imported`
      );

      window.setTimeout(
        () => {
          window.location.reload();
        },
        450
      );
    } catch (error) {
      console.error(
        'Studio media import failed',
        error
      );

      setStudioImportProgress(
        0,
        'Import failed',
        error?.message ||
          'Could not import media.'
      );

      window.setTimeout(
        () => {
          closeModal(
            studioImportDialog
          );
        },
        2600
      );
    } finally {
      if (studioImportSingle) {
        studioImportSingle.value = '';
      }

      if (studioImportMultiple) {
        studioImportMultiple.value = '';
      }
    }
  }

  function setTrackLibraryOpen(open) {
    const active = Boolean(open);

    trackLibraryDrawer?.classList.toggle(
      'open',
      active
    );

    trackLibraryDrawer?.setAttribute(
      'aria-hidden',
      active ? 'false' : 'true'
    );

    openTrackLibrary?.setAttribute(
      'aria-expanded',
      active ? 'true' : 'false'
    );

    if (trackLibraryBackdrop) {
      trackLibraryBackdrop.hidden =
        !active;
    }

    document.body.classList.toggle(
      'daw-library-open',
      active
    );

    if (!active) {
      document.querySelectorAll(
        '.daw-library-audio'
      ).forEach(audio => {
        if (!audio.paused) {
          audio.pause();
        }
      });

      closeLibraryCategoryMenu();
    }

    if (active) {
      setStudioMainMenu(false);
      setSongInfoOpen(false);

      requestAnimationFrame(() => {
        trackLibrarySearch?.focus();
      });
    }
  }

  function closeLibraryCategoryMenu() {
    if (!trackLibraryCategoryMenu) {
      return;
    }

    trackLibraryCategoryMenu.hidden = true;
    trackLibraryCategoryButton?.setAttribute(
      'aria-expanded',
      'false'
    );
  }

  function openLibraryCategoryMenu() {
    closeTrackRouteMenu();

    if (
      !trackLibraryCategory ||
      !trackLibraryCategoryMenu ||
      !trackLibraryCategoryButton
    ) {
      return;
    }

    trackLibraryCategoryMenu.innerHTML = [
      ...trackLibraryCategory.options
    ].map(option => `
      <button
        type="button"
        role="menuitemradio"
        aria-checked="${
          option.value ===
          trackLibraryCategory.value
            ? 'true'
            : 'false'
        }"
        class="${
          option.value ===
          trackLibraryCategory.value
            ? 'active'
            : ''
        }"
        data-library-category-value="${escapeHtml(option.value)}"
      >${escapeHtml(option.textContent || 'All categories')}</button>
    `).join('');

    trackLibraryCategoryMenu
      .querySelectorAll(
        '[data-library-category-value]'
      )
      .forEach(button => {
        button.addEventListener(
          'click',
          () => {
            trackLibraryCategory.value =
              button.dataset.libraryCategoryValue ||
              '';

            const selected =
              trackLibraryCategory
                .selectedOptions?.[0];

            if (trackLibraryCategoryButton) {
              trackLibraryCategoryButton.innerHTML =
                `${escapeHtml(
                  selected?.textContent ||
                  'All categories'
                )} <i>⌄</i>`;
            }

            closeLibraryCategoryMenu();
            filterTrackLibrary();
          }
        );
      });

    trackLibraryCategoryMenu.hidden = false;

    trackLibraryCategoryButton.setAttribute(
      'aria-expanded',
      'true'
    );

    const rect =
      trackLibraryCategoryButton
        .getBoundingClientRect();

    const width = Math.min(
      190,
      Math.max(
        150,
        trackLibraryCategoryMenu.scrollWidth
      )
    );

    const height =
      trackLibraryCategoryMenu.offsetHeight;

    // Open inward/left so the menu always remains readable.
    const left = Math.max(
      8,
      Math.min(
        window.innerWidth -
          width -
          8,
        rect.right - width
      )
    );

    const top = Math.max(
      8,
      Math.min(
        window.innerHeight -
          height -
          8,
        rect.bottom + 4
      )
    );

    trackLibraryCategoryMenu.style.left =
      `${Math.round(left)}px`;
    trackLibraryCategoryMenu.style.top =
      `${Math.round(top)}px`;
    trackLibraryCategoryMenu.style.width =
      `${Math.round(width)}px`;
  }

  function filterTrackLibrary() {
    const query = String(
      trackLibrarySearch?.value || ''
    )
      .trim()
      .toLowerCase();

    const category = String(
      trackLibraryCategory?.value || ''
    )
      .trim()
      .toLowerCase();

    let visible = 0;

    document.querySelectorAll(
      '[data-library-card]'
    ).forEach(card => {
      const search = String(
        card.dataset.librarySearch || ''
      ).toLowerCase();
      const role = String(
        card.dataset.libraryCategory || ''
      ).toLowerCase();

      const matchQuery =
        !query ||
        search.includes(query);

      const matchCategory =
        !category ||
        role === category;

      const show =
        matchQuery &&
        matchCategory;

      card.hidden = !show;

      if (show) {
        visible++;
      }
    });

    trackLibraryList
      ?.classList.toggle(
        'no-results',
        visible === 0
      );
  }

  async function addLibraryCardToTimeline(card) {
    if (!card) return;

    const button =
      card.querySelector(
        '[data-library-add-track]'
      );

    const preview =
      card.querySelector(
        '.daw-library-audio'
      );

    const sourceStemId = Number(
      card.dataset.libraryStemId || 0
    );

    const previewPosition =
      Number.isFinite(
        Number(preview?.currentTime)
      )
        ? Math.max(
            0,
            Number(preview.currentTime)
          )
        : 0;

    if (!sourceStemId) {
      return;
    }

    button && (button.disabled = true);

    try {
      const result =
        await studioProjectRequest(
          'add_library_stem',
          {
            track_id:trackId,
            source_stem_id:
              sourceStemId,
            source_start:
              previewPosition
          }
        );

      if (button) {
        button.textContent = 'Added';
        button.classList.add('added');
      }

      /*
       * A library insert is now a real track_stems record, not a special
       * temporary library-only lane. Reload so it receives the exact same
       * left-row, arrange clip, mixer strip, routing, plugins, automation,
       * delete behavior and persistence as every other imported track.
       */
      window.setTimeout(
        () => window.location.reload(),
        220
      );

      return result;
    } catch (error) {
      console.error(
        'Track Library insert failed',
        error
      );

      if (button) {
        button.disabled = false;
        button.textContent =
          'Add Track';
      }

      alert(
        error?.message ||
        'Could not add this library track.'
      );
    }
  }


  openTrackLibrary?.addEventListener(
    'click',
    () => {
      setTrackLibraryOpen(
        !trackLibraryDrawer?.classList.contains(
          'open'
        )
      );
    }
  );

  closeTrackLibrary?.addEventListener(
    'click',
    () => setTrackLibraryOpen(false)
  );

  trackLibraryBackdrop?.addEventListener(
    'click',
    () => setTrackLibraryOpen(false)
  );

  trackLibrarySearch?.addEventListener(
    'input',
    filterTrackLibrary
  );

  trackLibraryCategory?.addEventListener(
    'change',
    filterTrackLibrary
  );

  trackLibraryCategoryButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();

      if (
        trackLibraryCategoryMenu?.hidden === false
      ) {
        closeLibraryCategoryMenu();
      } else {
        openLibraryCategoryMenu();
      }
    }
  );

  document.querySelectorAll(
    '.daw-library-audio'
  ).forEach(audio => {
    audio.addEventListener('play',() => {
      document.querySelectorAll(
        '.daw-library-audio'
      ).forEach(other => {
        if (
          other !== audio &&
          !other.paused
        ) {
          other.pause();
        }
      });
    });
  });

  document.querySelectorAll(
    '[data-library-add-track]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () => {
        addLibraryCardToTimeline(
          button.closest(
            '[data-library-card]'
          )
        );
      }
    );
  });

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function openModal(modal) {
    if (!modal) return;

    modal.hidden = false;
    document.body.classList.add('daw-modal-open');
  }

  function closeModal(modal) {
    if (!modal) return;

    modal.hidden = true;

    if (
      masterBusDialog?.hidden !== false &&
      mixSaveDialog?.hidden !== false &&
      pluginDirectoryDialog?.hidden !== false &&
      addBusDialog?.hidden !== false &&
      recordingSaveDialog?.hidden !== false &&
      regionNoteDialog?.hidden !== false &&
      audioPermissionDialog?.hidden !== false &&
      exportAudioDialog?.hidden !== false &&
      openProjectDialog?.hidden !== false &&
      loadSongDialog?.hidden !== false &&
      newStudioProjectDialog?.hidden !== false &&
      studioImportDialog?.hidden !== false
    ) {
      document.body.classList.remove('daw-modal-open');
    }
  }

  studioMainMenuToggle?.addEventListener(
    'click',
    event => {
      event.stopPropagation();

      setStudioMainMenu(
        studioMainMenu?.hidden !== false
      );
    }
  );

  document.querySelectorAll(
    '[data-close-song-info]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () => setSongInfoOpen(false)
    );
  });

  saveRecordingButton?.addEventListener(
    'click',
    () => {
      savePendingRecording();
    }
  );

  discardRecordingButton?.addEventListener(
    'click',
    () => {
      discardPendingRecording();
    }
  );

  recordingSaveName?.addEventListener(
    'keydown',
    event => {
      if (event.key !== 'Enter') {
        return;
      }

      event.preventDefault();
      savePendingRecording();
    }
  );

  document.querySelectorAll(
    '[data-close-audio-permission]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () =>
        closeModal(
          audioPermissionDialog
        )
    );
  });

  retryAudioPermission?.addEventListener(
    'click',
    async () => {
      retryAudioPermission.disabled =
        true;

      closeModal(
        audioPermissionDialog
      );

      try {
        await connectRecordingInput();
      } catch (error) {
        console.error(
          'Audio permission retry failed',
          error
        );
      } finally {
        retryAudioPermission.disabled =
          false;
      }
    }
  );

  const formatLoadSongTime = seconds => {
    const clean = Math.max(
      0,
      Number(seconds || 0)
    );

    const minutes = Math.floor(
      clean / 60
    );
    const secs = Math.floor(
      clean % 60
    );

    return `${minutes}:${String(secs).padStart(2,'0')}`;
  };

  const loadSongPlayerParts = audio => {
    const player =
      audio?.closest(
        '[data-load-song-player]'
      );

    return {
      player,
      play:
        player?.querySelector(
          '[data-load-song-play]'
        ),
      progress:
        player?.querySelector(
          '[data-load-song-progress]'
        ),
      current:
        player?.querySelector(
          '[data-load-song-current]'
        ),
      total:
        player?.querySelector(
          '[data-load-song-total]'
        ),
      state:
        player?.querySelector(
          '[data-load-song-player-state]'
        )
    };
  };

  const loadSongSampleLimit = audio => {
    const configured = Math.max(
      1,
      Number(
        audio?.dataset.sampleSeconds ||
        30
      )
    );

    const duration = Number(
      audio?.duration || 0
    );

    return (
      Number.isFinite(duration) &&
      duration > 0
    )
      ? Math.min(
          configured,
          duration
        )
      : configured;
  };

  const updateLoadSongPlayer = audio => {
    if (!audio) return;

    const parts =
      loadSongPlayerParts(audio);
    const limit =
      loadSongSampleLimit(audio);
    const current = Math.max(
      0,
      Math.min(
        limit,
        Number(audio.currentTime || 0)
      )
    );

    if (parts.progress) {
      parts.progress.max =
        String(limit);
      parts.progress.value =
        String(current);

      const percent =
        limit > 0
          ? (
              current /
              limit
            ) * 100
          : 0;

      parts.progress.style.setProperty(
        '--sample-progress',
        `${Math.max(0,Math.min(100,percent))}%`
      );
    }

    if (parts.current) {
      parts.current.textContent =
        formatLoadSongTime(current);
    }

    if (parts.total) {
      parts.total.textContent =
        formatLoadSongTime(limit);
    }

    if (parts.play) {
      parts.play.textContent =
        audio.paused
          ? '▶'
          : 'Ⅱ';

      parts.play.setAttribute(
        'aria-label',
        audio.paused
          ? 'Play sample'
          : 'Pause sample'
      );
    }

    if (parts.state) {
      parts.state.textContent =
        audio.paused
          ? (
              current >= limit - .04
                ? 'ENDED'
                : (
                    current > .04
                      ? 'PAUSED'
                      : 'READY'
                  )
            )
          : 'PLAYING';
    }

    parts.player?.classList.toggle(
      'playing',
      !audio.paused
    );
  };

  const stopLoadSongSamples = except => {
    document.querySelectorAll(
      '[data-load-song-sample]'
    ).forEach(audio => {
      if (
        except &&
        audio === except
      ) {
        return;
      }

      if (!audio.paused) {
        audio.pause();
      }

      updateLoadSongPlayer(audio);
    });
  };

  const closeLoadSongDialog = () => {
    stopLoadSongSamples();
    closeModal(loadSongDialog);
  };

  const openLoadSongDialog = () => {
    setStudioMainMenu(false);
    setSongInfoOpen(false);

    if (!loadSongDialog) {
      console.error(
        'Load Song dialog was not found in the page.'
      );
      return;
    }

    if (loadSongSearch) {
      loadSongSearch.value = '';
    }

    document.querySelectorAll(
      '[data-load-song-card]'
    ).forEach(card => {
      card.hidden = false;
    });

    loadSongDialog.hidden = false;
    document.body.classList.add(
      'daw-modal-open'
    );

    document.querySelectorAll(
      '[data-load-song-sample]'
    ).forEach(audio => {
      updateLoadSongPlayer(audio);
    });

    requestAnimationFrame(() => {
      loadSongSearch?.focus();
    });
  };

  studioMainMenu
    ?.querySelector(
      '[data-studio-load-song]'
    )
    ?.addEventListener(
      'click',
      event => {
        event.preventDefault();
        event.stopPropagation();
        openLoadSongDialog();
      }
    );

  /*
   * Delegated fallback: if the Studio menu is rebuilt or another UI binding
   * changes, Load Song still opens instead of becoming a dead menu item.
   */
  document.addEventListener(
    'click',
    event => {
      const button =
        event.target.closest?.(
          '[data-studio-load-song]'
        );

      if (!button) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      openLoadSongDialog();
    }
  );

  document.querySelectorAll(
    '[data-close-load-song]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      closeLoadSongDialog
    );
  });

  loadSongSearch?.addEventListener(
    'input',
    () => {
      const query = String(
        loadSongSearch.value || ''
      )
        .trim()
        .toLowerCase();

      document.querySelectorAll(
        '[data-load-song-card]'
      ).forEach(card => {
        const search = String(
          card.dataset.loadSongSearch ||
          ''
        ).toLowerCase();

        card.hidden =
          Boolean(query) &&
          !search.includes(query);
      });
    }
  );

  document.querySelectorAll(
    '[data-load-song-player]'
  ).forEach(player => {
    const audio =
      player.querySelector(
        '[data-load-song-sample]'
      );
    const play =
      player.querySelector(
        '[data-load-song-play]'
      );
    const progress =
      player.querySelector(
        '[data-load-song-progress]'
      );

    if (!audio) {
      return;
    }

    const sync =
      () =>
        updateLoadSongPlayer(
          audio
        );

    audio.addEventListener(
      'loadedmetadata',
      sync
    );

    audio.addEventListener(
      'durationchange',
      sync
    );

    audio.addEventListener(
      'play',
      () => {
        stopLoadSongSamples(audio);
        sync();
      }
    );

    audio.addEventListener(
      'pause',
      sync
    );

    audio.addEventListener(
      'ended',
      sync
    );

    audio.addEventListener(
      'error',
      () => {
        const parts =
          loadSongPlayerParts(
            audio
          );

        audio.pause();

        if (parts.state) {
          parts.state.textContent =
            'UNAVAILABLE';
        }

        if (parts.play) {
          parts.play.disabled = true;
        }
      }
    );

    audio.addEventListener(
      'timeupdate',
      () => {
        const limit =
          loadSongSampleLimit(
            audio
          );

        if (
          Number(audio.currentTime || 0) >=
          limit - .025
        ) {
          audio.pause();

          try {
            audio.currentTime =
              limit;
          } catch (error) {}
        }

        sync();
      }
    );

    play?.addEventListener(
      'click',
      async () => {
        const limit =
          loadSongSampleLimit(
            audio
          );

        if (!audio.paused) {
          audio.pause();
          return;
        }

        if (
          Number(audio.currentTime || 0) >=
          limit - .04
        ) {
          try {
            audio.currentTime = 0;
          } catch (error) {}
        }

        stopLoadSongSamples(audio);

        try {
          await audio.play();
        } catch (error) {
          console.error(
            'Load Song sample playback failed',
            error
          );
        }
      }
    );

    progress?.addEventListener(
      'input',
      () => {
        const limit =
          loadSongSampleLimit(
            audio
          );

        const next = Math.max(
          0,
          Math.min(
            limit,
            Number(
              progress.value ||
              0
            )
          )
        );

        try {
          audio.currentTime =
            next;
        } catch (error) {}

        sync();
      }
    );

    sync();
  });


  const openExportAudioDialog = () => {
    setStudioMainMenu(false);
    setSongInfoOpen(false);

    openModal(
      exportAudioDialog
    );
  };

  studioMainMenu
    ?.querySelector(
      '[data-studio-export-audio]'
    )
    ?.addEventListener(
      'click',
      event => {
        event.preventDefault();
        event.stopPropagation();
        openExportAudioDialog();
      }
    );

  document.querySelectorAll(
    '[data-close-export-audio]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () =>
        closeModal(
          exportAudioDialog
        )
    );
  });

  const openOwnedProjectsDialog = () => {
    setStudioMainMenu(false);
    setSongInfoOpen(false);

    if (!openProjectDialog) {
      return;
    }

    if (openProjectSearch) {
      openProjectSearch.value = '';
    }

    document.querySelectorAll(
      '[data-open-project-card]'
    ).forEach(card => {
      card.hidden = false;
    });

    openModal(openProjectDialog);

    requestAnimationFrame(() => {
      openProjectSearch?.focus();
    });
  };

  studioMainMenu
    ?.querySelector(
      '[data-studio-open-project]'
    )
    ?.addEventListener(
      'click',
      event => {
        event.preventDefault();
        event.stopPropagation();
        openOwnedProjectsDialog();
      }
    );

  document.querySelectorAll(
    '[data-close-open-project]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () => closeModal(openProjectDialog)
    );
  });

  openProjectSearch?.addEventListener(
    'input',
    () => {
      const query = String(
        openProjectSearch.value || ''
      )
        .trim()
        .toLowerCase();

      document.querySelectorAll(
        '[data-open-project-card]'
      ).forEach(card => {
        const search = String(
          card.dataset.openProjectSearch ||
          ''
        ).toLowerCase();

        card.hidden =
          Boolean(query) &&
          !search.includes(query);
      });
    }
  );

  studioMainMenu
    ?.querySelector(
      '[data-studio-song-info]'
    )
    ?.addEventListener(
      'click',
      event => {
        event.preventDefault();
        event.stopPropagation();

        setStudioMainMenu(false);
        setSongInfoOpen(true);
      }
    );

  studioMainMenu
    ?.querySelector(
      '[data-studio-new-project]'
    )
    ?.addEventListener(
      'click',
      () => {
        setStudioMainMenu(false);

        if (newStudioProjectName) {
          newStudioProjectName.value =
            'Untitled Project';
        }

        if (newStudioProjectTempo) {
          newStudioProjectTempo.value =
            String(
              Math.round(
                sessionTempo *
                10
              ) / 10
            );
        }

        openModal(
          newStudioProjectDialog
        );

        requestAnimationFrame(() => {
          newStudioProjectName
            ?.focus();
          newStudioProjectName
            ?.select();
        });
      }
    );

  document.querySelectorAll(
    '[data-close-new-project]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () =>
        closeModal(
          newStudioProjectDialog
        )
    );
  });

  createStudioProjectButton
    ?.addEventListener(
      'click',
      async () => {
        const name =
          String(
            newStudioProjectName
              ?.value ||
            'Untitled Project'
          ).trim();

        createStudioProjectButton.disabled =
          true;

        try {
          const result =
            await studioProjectRequest(
              'create_project',
              {
                project_name:
                  name ||
                  'Untitled Project',
                tempo_bpm:
                  newStudioProjectTempo
                    ?.value ||
                  120,
                time_signature:
                  newStudioProjectSignature
                    ?.value ||
                  '4/4'
              }
            );

          if (result.redirect) {
            window.location.href =
              result.redirect;
          }
        } catch (error) {
          alert(
            error?.message ||
            'Could not create project.'
          );
        } finally {
          createStudioProjectButton.disabled =
            false;
        }
      }
    );

  newStudioProjectName
    ?.addEventListener(
      'keydown',
      event => {
        if (event.key === 'Enter') {
          event.preventDefault();
          createStudioProjectButton
            ?.click();
        }
      }
    );

  async function createEmptyRecordingTrack() {
    setStudioMainMenu(false);

    const nextNumber =
      stems.length + 1;

    try {
      const data =
        await studioProjectRequest(
          'create_empty_track',
          {
            track_id:trackId,
            track_name:
              `Audio Track ${nextNumber}`,
            start_offset:
              globalPosition(),
            session_tempo:
              sessionTempo,
            sample_rate:
              recordingSampleRate ||
              48000
          }
        );

      const stemId =
        Number(
          data.stem_id || 0
        );

      const nextUrl =
        new URL(
          window.location.href
        );

      nextUrl.searchParams.set(
        'track',
        String(trackId)
      );

      if (stemId > 0) {
        nextUrl.searchParams.set(
          'arm',
          String(stemId)
        );
      }

      window.location.href =
        nextUrl.toString();
    } catch (error) {
      alert(
        error?.message ||
        'Could not create a new recording track.'
      );
    }
  }

  function openNewTrackPicker() {
    createEmptyRecordingTrack();
  }


  studioMainMenu
    ?.querySelector(
      '[data-studio-import-single]'
    )
    ?.addEventListener(
      'click',
      openNewTrackPicker
    );

  studioMainMenu
    ?.querySelector(
      '[data-studio-import-multiple]'
    )
    ?.addEventListener(
      'click',
      () => {
        setStudioMainMenu(false);
        studioImportMultiple?.click();
      }
    );

  studioImportSingle
    ?.addEventListener(
      'change',
      () => {
        uploadStudioMedia(
          studioImportSingle.files
        );
      }
    );

  studioImportMultiple
    ?.addEventListener(
      'change',
      () => {
        uploadStudioMedia(
          studioImportMultiple.files
        );
      }
    );

  studioMainMenu
    ?.querySelector(
      '[data-studio-save-account]'
    )
    ?.addEventListener(
      'click',
      async () => {
        setStudioMainMenu(false);

        try {
          const result =
            await studioProjectRequest(
              'save_to_account',
              {
                track_id:trackId
              }
            );

          alert(
            result.message ||
            'Project saved to your account.'
          );

          window.location.reload();
        } catch (error) {
          alert(
            error?.message ||
            'Could not save project to your account.'
          );
        }
      }
    );

  studioMainMenu
    ?.querySelector(
      '[data-studio-delete-project]'
    )
    ?.addEventListener(
      'click',
      async () => {
        setStudioMainMenu(false);

        if (
          !window.confirm(
            'Delete this entire project and all of its imported tracks? This cannot be undone after the page closes.'
          )
        ) {
          return;
        }

        try {
          const result =
            await studioProjectRequest(
              'delete_project',
              {
                track_id:trackId
              }
            );

          window.location.href =
            result.redirect ||
            '/admin/tracks.php';
        } catch (error) {
          alert(
            error?.message ||
            'Could not delete project.'
          );
        }
      }
    );



  document.querySelectorAll(
    '[data-open-track-inspector]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      event => {
        event.stopPropagation();

        const stem =
          stemById(
            Number(
              button.dataset.openTrackInspector ||
              0
            )
          );

        if (stem) {
          setTrackInspectorOpen(
            true,
            stem
          );
        }
      }
    );
  });

  document.addEventListener(
    'contextmenu',
    event => {
      if (
        event.target.closest(
          'input,select,textarea,audio'
        )
      ) {
        return;
      }

      const target =
        event.target.closest(
          '[data-stem-id],' +
          '[data-arrange-stem],' +
          '[data-mixer-stem]'
        );

      if (!target) {
        closeTrackContextMenu();
        return;
      }

      const stemId = Number(
        target.dataset.stemId ||
        target.dataset.arrangeStem ||
        target.dataset.mixerStem ||
        0
      );

      const stem =
        stemById(stemId);

      if (!stem) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();

      markSelectedStem(stem.id);
      openTrackContextMenu(
        stem,
        event
      );
    }
  );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-settings]'
    )
    ?.addEventListener(
      'click',
      () => {
        const stem =
          stemById(
            contextMenuStemId
          );

        closeTrackContextMenu();

        if (stem) {
          setTrackInspectorOpen(
            true,
            stem
          );
        }
      }
    );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-automation]'
    )
    ?.addEventListener(
      'click',
      () => {
        const stem =
          stemById(
            contextMenuStemId
          );

        closeTrackContextMenu();

        if (stem) {
          setAutomationOpen(
            stem,
            !stem.automationOpen
          );
        }
      }
    );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-arm]'
    )
    ?.addEventListener(
      'click',
      () => {
        const stemId =
          contextMenuStemId;

        closeTrackContextMenu();

        if (
          !recordingActive &&
          !recordingStopping &&
          !recordingPendingSave
        ) {
          setArmedStem(
            stemId
          );
        }
      }
    );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-mute]'
    )
    ?.addEventListener(
      'click',
      () => {
        const stem =
          stemById(
            contextMenuStemId
          );

        closeTrackContextMenu();

        if (stem) {
          stem.muted =
            !stem.muted;
          updateGains();
          scheduleLocalSave(0);
        }
      }
    );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-solo]'
    )
    ?.addEventListener(
      'click',
      () => {
        const stem =
          stemById(
            contextMenuStemId
          );

        closeTrackContextMenu();

        if (stem) {
          stem.solo =
            !stem.solo;
          updateGains();
          scheduleLocalSave(0);
        }
      }
    );

  studioTrackContextMenu
    ?.querySelector(
      '[data-context-track-delete]'
    )
    ?.addEventListener(
      'click',
      async () => {
        const stemId =
          contextMenuStemId;

        closeTrackContextMenu();

        try {
          await deleteStudioStemById(
            stemId
          );
        } catch (error) {
          alert(
            error?.message ||
            'Could not delete track.'
          );
        }
      }
    );

  document.addEventListener(
    'pointerdown',
    event => {
      if (
        studioTrackContextMenu?.hidden === false &&
        !studioTrackContextMenu.contains(
          event.target
        )
      ) {
        closeTrackContextMenu();
      }

      if (
        studioMainMenu?.hidden === false &&
        !studioMainMenu.contains(
          event.target
        ) &&
        !studioMainMenuToggle?.contains(
          event.target
        )
      ) {
        setStudioMainMenu(false);
      }

      if (
        songInfoMenu?.hidden === false &&
        !songInfoMenu.contains(
          event.target
        ) &&
        !songInfoToggle?.contains(
          event.target
        )
      ) {
        setSongInfoOpen(false);
      }
    }
  );

  openTrackInspector?.addEventListener(
    'click',
    () => {
      setTrackInspectorOpen(
        !trackInspector?.classList.contains(
          'open'
        )
      );
    }
  );

  closeTrackInspector?.addEventListener(
    'click',
    () =>
      setTrackInspectorOpen(
        false
      )
  );

  trackInspectorBackdrop?.addEventListener(
    'click',
    () =>
      setTrackInspectorOpen(
        false
      )
  );

  inspectorSaveTrackDetails?.addEventListener(
    'click',
    async () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) {
        return;
      }

      const name = String(
        inspectorTrackNameInput?.value ||
        ''
      ).trim();
      const role = String(
        inspectorTrackRole?.value ||
        'Other'
      );

      if (!name) {
        inspectorTrackNameInput?.focus();
        return;
      }

      inspectorSaveTrackDetails.disabled =
        true;

      try {
        const result =
          await studioProjectRequest(
            'update_stem',
            {
              track_id:trackId,
              stem_id:stem.id,
              stem_name:name,
              stem_role:role
            }
          );

        stem.name =
          String(
            result.stem_name ||
            name
          );
        stem.label =
          stem.name;
        stem.role =
          String(
            result.stem_role ||
            role
          );

        stem.leftRow
          ?.querySelector(
            '.daw-track-select strong'
          )
          ?.replaceChildren(
            document.createTextNode(
              stem.name
            )
          );

        renderStemClips(stem);
        updateTrackInspector();

        setSaveStatus(
          'TRACK SAVED',
          'saved'
        );
      } catch (error) {
        alert(
          error?.message ||
          'Could not save track details.'
        );
      } finally {
        inspectorSaveTrackDetails.disabled =
          false;
      }
    }
  );

  inspectorTrackInput?.addEventListener(
    'change',
    async () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      stem.recordingInputDeviceId =
        String(
          inspectorTrackInput.value ||
          ''
        );

      if (stem.inputSelect) {
        stem.inputSelect.value =
          stem.recordingInputDeviceId;
        stem.inputSelect.dispatchEvent(
          new Event(
            'change',
            {
              bubbles:true
            }
          )
        );
      }

      scheduleLocalSave(0);
      updateTrackInspector();
    }
  );

  inspectorTrackBus?.addEventListener(
    'change',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setTrackGroup(
        stem,
        inspectorTrackBus.value
      );

      updateTrackInspector();
    }
  );

  inspectorVolume?.addEventListener(
    'input',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      stem.userGain =
        clamp(
          inspectorVolume.value,
          0,
          1.5
        );

      if (stem.volume) {
        stem.volume.value =
          String(stem.userGain);
      }

      updateGains();
      updateTrackInspector();
    }
  );

  inspectorPan?.addEventListener(
    'input',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setStemPan(
        stem,
        inspectorPan.value
      );

      updateTrackInspector();
    }
  );

  inspectorTrim?.addEventListener(
    'input',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setTrackTrim(
        stem,
        inspectorTrim.value
      );

      updateTrackInspector();
    }
  );

  inspectorSendA?.addEventListener(
    'input',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setStemSend(
        stem,
        'a',
        inspectorSendA.value
      );

      updateTrackInspector();
    }
  );

  inspectorSendB?.addEventListener(
    'input',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setStemSend(
        stem,
        'b',
        inspectorSendB.value
      );

      updateTrackInspector();
    }
  );

  inspectorArm?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (
        !stem ||
        recordingActive ||
        recordingStopping
      ) {
        return;
      }

      setArmedStem(
        stem.id
      );

      updateTrackInspector();
    }
  );

  inspectorMute?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      stem.muted =
        !stem.muted;

      updateGains();
      scheduleLocalSave(0);
      updateTrackInspector();
    }
  );

  inspectorSolo?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      stem.solo =
        !stem.solo;

      updateGains();
      scheduleLocalSave(0);
      updateTrackInspector();
    }
  );

  inspectorAutomation?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) return;

      setAutomationOpen(
        stem,
        !stem.automationOpen
      );

      updateTrackInspector();
    }
  );

  const selectedInspectorClip = () => {
    const selected =
      selectedArrangementData();

    if (
      selected?.kind !== 'stem'
    ) {
      return null;
    }

    return selected;
  };

  inspectorAddPlugin?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) {
        return;
      }

      openPluginDirectory(
        stem.pluginKey
      );
    }
  );

  inspectorOpenPluginRack?.addEventListener(
    'click',
    () => {
      const stem =
        inspectorSelectedStem();

      if (!stem) {
        return;
      }

      setPluginRack(
        true,
        Math.max(
          520,
          pluginRackHeight
        )
      );

      stem.mixer?.scrollIntoView({
        block:'nearest',
        inline:'center',
        behavior:'smooth'
      });
    }
  );

  inspectorClipGain?.addEventListener(
    'input',
    () => {
      const selected =
        selectedInspectorClip();

      if (!selected) return;

      selected.clip.gainDb =
        clamp(
          inspectorClipGain.value,
          -24,
          12
        );

      updateStemClipDom(
        selected.stem,
        selected.clip
      );
      scheduleLocalSave(0);
    }
  );

  inspectorFadeIn?.addEventListener(
    'input',
    () => {
      const selected =
        selectedInspectorClip();

      if (!selected) return;

      selected.clip.fadeIn =
        clamp(
          inspectorFadeIn.value,
          0,
          selected.clip.timelineLength
        );

      updateStemClipDom(
        selected.stem,
        selected.clip
      );
      scheduleLocalSave(0);
    }
  );

  inspectorFadeOut?.addEventListener(
    'input',
    () => {
      const selected =
        selectedInspectorClip();

      if (!selected) return;

      selected.clip.fadeOut =
        clamp(
          inspectorFadeOut.value,
          0,
          selected.clip.timelineLength
        );

      updateStemClipDom(
        selected.stem,
        selected.clip
      );
      scheduleLocalSave(0);
    }
  );

  inspectorClipMute?.addEventListener(
    'click',
    () => {
      const selected =
        selectedInspectorClip();

      if (!selected) return;

      selected.clip.muted =
        !selected.clip.muted;

      updateStemClipDom(
        selected.stem,
        selected.clip
      );
      scheduleLocalSave(0);
      updateGains();
    }
  );

  inspectorAutoCrossfade?.addEventListener(
    'click',
    () => {
      const selected =
        selectedInspectorClip();

      if (!selected) return;

      if (
        !autoCrossfadeStemClips(
          selected.stem,
          selected.clip
        )
      ) {
        inspectorClipName.textContent =
          'No overlapping clip to crossfade';
      }
    }
  );

  recordCountInBars?.addEventListener(
    'change',
    () => {
      setRecordingCountInBarsValue(
        recordCountInBars.value
      );
    }
  );

  recordMetronomeToggle?.addEventListener(
    'click',
    () => {
      setMetronomeEnabled(
        !recordingMetronomeEnabled,
        true
      );
    }
  );

  studioMetronomeButton?.addEventListener(
    'click',
    event => {
      event.stopPropagation();
      const opening =
        Boolean(studioMetronomeMenu?.hidden);

      if (studioMetronomeMenu) {
        studioMetronomeMenu.hidden =
          !opening;
      }

      studioMetronomeButton.setAttribute(
        'aria-expanded',
        opening ? 'true' : 'false'
      );

      if (opening) {
        updateRecordingSettingsUi();
      }
    }
  );

  studioMetronomeToggle?.addEventListener(
    'click',
    () => {
      setMetronomeEnabled(
        !recordingMetronomeEnabled,
        true
      );
    }
  );

  studioMetronomeCountIn?.addEventListener(
    'change',
    () => {
      setRecordingCountInBarsValue(
        studioMetronomeCountIn.value
      );
    }
  );

  document.addEventListener(
    'click',
    event => {
      if (
        studioMetronomeMenu &&
        !studioMetronomeMenu.hidden &&
        !event.target.closest(
          '.daw-header-metronome'
        )
      ) {
        studioMetronomeMenu.hidden = true;
        studioMetronomeButton?.setAttribute(
          'aria-expanded',
          'false'
        );
      }
    }
  );

  recordPunchToggle?.addEventListener(
    'click',
    () => {
      if (
        !recordingPunchEnabled &&
        recordingPunchEnd <=
          recordingPunchStart
      ) {
        setPunchRangeFromCurrentContext();
      } else {
        recordingPunchEnabled =
          !recordingPunchEnabled;
      }

      saveRecordingSettings();
      updateRecordingSettingsUi();
    }
  );

  recordPunchFromLoop?.addEventListener(
    'click',
    () => {
      setPunchRangeFromCurrentContext();
    }
  );

  inspectorRecordButton?.addEventListener(
    'click',
    () => {
      startStudioRecording();
    }
  );

  inspectorStopRecordButton?.addEventListener(
    'click',
    () => {
      if (recordingCountInActive) {
        recordingCountInCancel =
          true;
        return;
      }

      stopStudioRecording();
    }
  );

  // ---------------------------------------------------------
  // Main controls.
  // ---------------------------------------------------------
  playButton?.addEventListener('click', () => {
    if (playing) {
      pauseAll();
    } else {
      playAll().catch(error => {
        console.error(error);
        alert('Could not start Stem Studio playback.');
      });
    }
  });

  studioRecordButton?.addEventListener(
    'click',
    () => {
      if (recordingActive) {
        stopStudioRecording();
      } else {
        startStudioRecording();
      }
    }
  );

  studioInputAccess?.addEventListener(
    'click',
    async () => {
      studioInputAccess.disabled = true;

      try {
        await connectRecordingInput();
      } catch (error) {
        setRecordingStatus(
          error?.message ||
          'INPUT FAILED',
          'error'
        );

        console.error(
          'Audio input connection failed',
          error
        );
      } finally {
        if (!recordingActive) {
          studioInputAccess.disabled = false;
        }
      }
    }
  );

  studioAudioInput?.addEventListener(
    'change',
    async () => {
      if (recordingActive) {
        return;
      }

      try {
        await connectRecordingInput(
          studioAudioInput.value
        );
      } catch (error) {
        setRecordingStatus(
          error?.message ||
          'INPUT FAILED',
          'error'
        );
      }
    }
  );

  studioMonitorButton?.addEventListener(
    'click',
    async () => {
      try {
        if (!recordingStream) {
          await connectRecordingInput();
        }

        recordingMonitorEnabled =
          !recordingMonitorEnabled;

        updateRecordingMonitor();
      } catch (error) {
        setRecordingStatus(
          error?.message ||
          'MONITOR FAILED',
          'error'
        );
      }
    }
  );

  masterVolume?.addEventListener('input', () => {
    const value = Number(masterVolume.value || 1);

    if (masterValue) {
      masterValue.textContent = dbText(value);
    }

    if (masterGain && context) {
      masterGain.gain.setTargetAtTime(
        value,
        context.currentTime,
        0.008
      );
    }

    scheduleLocalSave();
  });

  auxReturnA?.addEventListener('input', () => {
    setReturnLevel(
      'a',
      Number(auxReturnA.value || 0.8)
    );
  });

  auxReturnB?.addEventListener('input', () => {
    setReturnLevel(
      'b',
      Number(auxReturnB.value || 0.7)
    );
  });

  document.querySelectorAll('[data-group-volume]')
    .forEach(input => {
      input.addEventListener('input',() => {
        const group = input.dataset.groupVolume;
        if (!groupState[group]) return;

        groupState[group].volume = clamp(
          input.value,
          0,
          1.5
        );
        updateGroupBus(group);
      });
    });

  document.querySelectorAll('[data-group-mute]')
    .forEach(button => {
      button.addEventListener('click',() => {
        const group = button.dataset.groupMute;
        if (!groupState[group]) return;

        groupState[group].muted =
          !groupState[group].muted;
        updateGroupBus(group);
      });
    });

  stems.forEach(stem => {
    stem.muteButtons.forEach(button => {
      button.addEventListener('click', event => {
        event.stopPropagation();

        stem.muted = !stem.muted;
        updateGains();
      });
    });

    stem.soloButtons.forEach(button => {
      button.addEventListener('click', event => {
        event.stopPropagation();

        stem.solo = !stem.solo;
        updateGains();
      });
    });

    stem.inputSelect?.addEventListener(
      'change',
      async () => {
        if (
          recordingActive ||
          recordingStopping
        ) {
          if (stem.inputSelect) {
            stem.inputSelect.value =
              stem.recordingInputDeviceId ||
              '';
          }

          return;
        }

        stem.recordingInputDeviceId =
          String(
            stem.inputSelect?.value ||
            ''
          );

        stem.inputSelect.title =
          stem.inputSelect
            ?.selectedOptions?.[0]
            ?.textContent ||
          'Recording input';

        if (
          studioAudioInput &&
          stem.recordingInputDeviceId
        ) {
          const exists =
            [
              ...studioAudioInput.options
            ].some(
              option =>
                option.value ===
                stem.recordingInputDeviceId
            );

          if (exists) {
            studioAudioInput.value =
              stem.recordingInputDeviceId;
          }
        }

        scheduleLocalSave(0);

        if (
          recordingArmedStemId ===
            stem.id &&
          stem.recordingInputDeviceId
        ) {
          setRecordingStatus(
            'SWITCHING INPUT…'
          );

          try {
            await connectRecordingInput(
              stem.recordingInputDeviceId
            );

            setRecordingStatus(
              `ARMED · ${stem.name || stem.label}`,
              'ready'
            );
          } catch (error) {
            setRecordingStatus(
              error?.message ||
              'INPUT FAILED',
              'error'
            );
          }
        }
      }
    );

    const handleTrackArmClick =
      event => {
        event.stopPropagation();

        if (
          recordingActive ||
          recordingStopping ||
          recordingPendingSave
        ) {
          return;
        }

        setArmedStem(
          stem.id
        );
      };

    stem.armButton?.addEventListener(
      'click',
      handleTrackArmClick
    );

    stem.sidebarArmButton?.addEventListener(
      'click',
      handleTrackArmClick
    );

    stem.volume?.addEventListener('input', () => {
      stem.userGain = Number(
        stem.volume.value || 0
      );

      updateGains();
    });

    stem.trim?.addEventListener('input',() => {
      setTrackTrim(
        stem,
        Number(stem.trim.value || 0)
      );
    });

    stem.groupSelect?.addEventListener(
      'change',
      () => {
        setTrackGroup(
          stem,
          stem.groupSelect.value
        );
      }
    );

    stem.groupMenuButton?.addEventListener(
      'click',
      event => {
        event.stopPropagation();

        if (
          openRouteStem === stem &&
          trackRoutePopover?.hidden === false
        ) {
          closeTrackRouteMenu();
          return;
        }

        openTrackRouteMenu(stem);
      }
    );

    stem.auxSendA?.addEventListener('input', () => {
      setStemSend(
        stem,
        'a',
        Number(stem.auxSendA.value || 0)
      );
    });

    stem.auxSendB?.addEventListener('input', () => {
      setStemSend(
        stem,
        'b',
        Number(stem.auxSendB.value || 0)
      );
    });

    bindAutomationEditor(stem);

    stem.leftRow
      ?.querySelector('[data-track-select]')
      ?.addEventListener(
        'click',
        () => selectStem(stem)
      );

    stem.arrangeRow?.addEventListener(
      'click',
      () => selectStem(stem)
    );
  });

  document.querySelectorAll('[data-master-plugin]')
    .forEach(button => {
      button.addEventListener('click', () => {
        const name = button.dataset.masterPlugin;

        if (!(name in pluginState)) return;

        setPluginState(
          name,
          !pluginState[name]
        );

        ensureAudioGraph();
      });
    });

  resetMixButton?.addEventListener('click', resetMix);

  document.getElementById('openMasterBusChannel')
    ?.addEventListener(
      'click',
      () => openModal(masterBusDialog)
    );

  document.querySelectorAll('[data-close-master-bus]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(masterBusDialog)
      );
    });

  // ---------------------------------------------------------
  // Pull-up per-track plugin rack.
  // ---------------------------------------------------------
  if (pluginRackHandle) {
    let rackPointerId = null;
    let rackStartY = 0;
    let rackStartHeight = 348;
    let rackMoved = false;

    pluginRackHandle.addEventListener('pointerdown', event => {
      if (event.button !== 0) return;

      rackPointerId = event.pointerId;
      rackStartY = event.clientY;
      rackStartHeight = pluginRackOpen
        ? pluginRackHeight
        : 348;
      rackMoved = false;

      pluginRackHandle.setPointerCapture(rackPointerId);
      pluginRackHandle.classList.add('dragging-rack');
      event.preventDefault();
    });

    pluginRackHandle.addEventListener('pointermove', event => {
      if (event.pointerId !== rackPointerId) return;

      const delta = rackStartY - event.clientY;

      if (Math.abs(delta) > 4) {
        rackMoved = true;
      }

      const nextHeight = Math.max(
        320,
        Math.min(820, rackStartHeight + delta)
      );

      pluginRackOpen = nextHeight >= 392;
      setPluginRack(
        pluginRackOpen,
        nextHeight,
        false
      );
    });

    const finishRackDrag = event => {
      if (event.pointerId !== rackPointerId) return;

      try {
        pluginRackHandle.releasePointerCapture(rackPointerId);
      } catch (error) {}

      rackPointerId = null;
      pluginRackHandle.classList.remove('dragging-rack');

      if (rackMoved) {
        setPluginRack(
          pluginRackOpen,
          pluginRackOpen
            ? Math.max(500, pluginRackHeight)
            : 348
        );
      }
    };

    pluginRackHandle.addEventListener('pointerup', finishRackDrag);
    pluginRackHandle.addEventListener('pointercancel', finishRackDrag);

    pluginRackHandle.addEventListener('click', () => {
      if (rackMoved) {
        rackMoved = false;
        return;
      }

      setPluginRack(
        !pluginRackOpen,
        pluginRackOpen ? 348 : 560
      );
    });
  }

  stems.forEach(stem => {
    renderTrackPluginList(stem);

    stem.addPluginButton?.addEventListener(
      'click',
      event => {
        event.stopPropagation();
        openPluginDirectory(
          stem.pluginKey
        );
      }
    );
  });

  Object.values(fixedPluginTargets)
    .forEach(target => {
      renderPluginTargetList(target);

      target.addPluginButton
        ?.addEventListener(
          'click',
          event => {
            event.stopPropagation();
            openPluginDirectory(
              target.key
            );
          }
        );
    });

  addMixerBus?.addEventListener(
    'click',
    () => {
      if (newBusName) {
        newBusName.value =
          `BUS ${customBuses.length + 1}`;
      }

      openModal(addBusDialog);

      requestAnimationFrame(() => {
        newBusName?.focus();
        newBusName?.select();
      });
    }
  );

  document.querySelectorAll(
    '[data-close-add-bus]'
  ).forEach(button => {
    button.addEventListener(
      'click',
      () => closeModal(addBusDialog)
    );
  });

  const submitNewBus = () => {
    if (customBuses.length >= 12) {
      alert(
        'Stem Studio supports up to 12 custom buses per mix.'
      );
      return;
    }

    const name = cleanBusName(
      newBusName?.value
    );

    createCustomBus(name);
    closeModal(addBusDialog);
  };

  createMixerBusButton?.addEventListener(
    'click',
    submitNewBus
  );

  newBusName?.addEventListener(
    'keydown',
    event => {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitNewBus();
      }
    }
  );

  document.querySelectorAll('[data-plugin-type]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => {
          const target =
            pluginTargetByKey(
              pluginTargetKey
            );
          const type =
            button.dataset.pluginType;

          if (
            !target ||
            ![
              'eq5',
              'delay',
              'compressor',
              'reverb',
              'limiter'
            ].includes(type)
          ) {
            return;
          }

          addPluginToTarget(
            target,
            type
          );
        }
      );
    });

  document.querySelectorAll('[data-close-plugin-directory]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(pluginDirectoryDialog)
      );
    });

  pluginBypassButton?.addEventListener(
    'click',
    () => {
      const target =
        pluginTargetByKey(
          pluginTargetKey
        );
      const plugin =
        target?.plugins?.[
          pluginEditIndex
        ];

      if (!target || !plugin) return;

      plugin.enabled = !plugin.enabled;

      renderPluginTargetList(target);

      if (context) {
        rebuildPluginTargetGraph(target);
      }

      openPluginEditor(
        target.pluginKey ||
        target.key,
        pluginEditIndex
      );

      scheduleLocalSave();
    }
  );

  pluginRemoveButton?.addEventListener(
    'click',
    () => {
      const target =
        pluginTargetByKey(
          pluginTargetKey
        );

      if (
        !target ||
        pluginEditIndex < 0
      ) {
        return;
      }

      target.plugins.splice(
        pluginEditIndex,
        1
      );
      pluginEditIndex = -1;

      renderPluginTargetList(target);

      if (context) {
        rebuildPluginTargetGraph(target);
      }

      scheduleLocalSave(0);
      closeModal(pluginDirectoryDialog);
    }
  );

  studioSaveButton?.addEventListener(
    'click',
    () => {
      saveCurrentVersion();
    }
  );

  studioSaveAsButton?.addEventListener(
    'click',
    () => {
      openSavedVersionsDialog(
        true
      );
    }
  );

  studioMainMenu
    ?.querySelector(
      '[data-studio-saved-versions]'
    )
    ?.addEventListener(
      'click',
      async event => {
        event.preventDefault();
        event.stopPropagation();

        setStudioMainMenu(false);

        await openSavedVersionsDialog(
          false
        );
      }
    );

  document.querySelectorAll('[data-close-mix-dialog]')
    .forEach(button => {
      button.addEventListener(
        'click',
        () => closeModal(mixSaveDialog)
      );
    });

  saveStemMixButton?.addEventListener(
    'click',
    async () => {
      const name = String(
        stemMixName?.value ||
        projectTitle
      ).trim() || projectTitle;

      saveStemMixButton.disabled =
        true;

      if (studioSaveButton) {
        studioSaveButton.disabled =
          true;
      }

      if (studioSaveAsButton) {
        studioSaveAsButton.disabled =
          true;
      }

      setSaveStatus(
        'SAVING AS…',
        'saving'
      );

      try {
        /*
         * Save As always creates a new version. It never overwrites the
         * currently active saved version.
         */
        const data =
          await mixRequest(
            'save',
            {
              mix_id:0,
              mix_name:name,
              state:collectMixState()
            }
          );

        setSelectedMixRef(
          Number(
            data.mix_id ||
            0
          ),
          String(
            data.mix_name ||
            name
          )
        );

        setSaveStatus(
          'SAVED AS',
          'saved'
        );

        await refreshMixList();

        closeModal(
          mixSaveDialog
        );
      } catch (error) {
        setSaveStatus(
          'SAVE FAILED',
          'error'
        );

        alert(
          error?.message ||
          'Could not save this Studio version.'
        );
      } finally {
        saveStemMixButton.disabled =
          false;

        if (studioSaveButton) {
          studioSaveButton.disabled =
            false;
        }

        if (studioSaveAsButton) {
          studioSaveAsButton.disabled =
            false;
        }
      }
    }
  );

  /*
   * Browser tabs own Ctrl/Cmd+T at the browser-chrome level in Chrome/Edge,
   * so a normal web page cannot reliably suppress New Tab. Keep an attempted
   * Ctrl/Cmd+T handler for environments that deliver it, and provide Alt+T
   * (plus Ctrl/Cmd+Alt+T) as the reliable Studio shortcut.
   */
  window.addEventListener(
    'keydown',
    event => {
      const lowerKey =
        String(event.key || '')
          .toLowerCase();

      if (lowerKey !== 't') {
        return;
      }

      const textEditingTarget =
        event.target?.closest?.(
          'input:not([type="range"]):not([type="number"]),' +
          'textarea,' +
          '[contenteditable="true"]'
        );

      if (textEditingTarget) {
        return;
      }

      const commandKey =
        event.ctrlKey ||
        event.metaKey;

      const reliableNewTrackShortcut = (
        event.altKey &&
        !event.shiftKey
      );

      const attemptedBrowserShortcut = (
        commandKey &&
        !event.altKey &&
        !event.shiftKey
      );

      if (
        !reliableNewTrackShortcut &&
        !attemptedBrowserShortcut
      ) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      event.stopImmediatePropagation?.();

      openNewTrackPicker();
    },
    true
  );

  window.addEventListener(
    'beforeunload',
    event => {
      if (
        !recordingActive &&
        !recordingCountInActive &&
        !recordingPendingSave
      ) {
        return;
      }

      event.preventDefault();
      event.returnValue = '';
    }
  );

  document.addEventListener('keydown', event => {
    const commandKey =
      event.ctrlKey ||
      event.metaKey;

    const lowerKey =
      event.key.toLowerCase();

    const textEditingTarget =
      event.target?.closest?.(
        'input:not([type="range"]):not([type="number"]),' +
        'textarea,' +
        '[contenteditable="true"]'
      );

    const undoShortcut = (
      commandKey &&
      !event.altKey &&
      !event.shiftKey &&
      lowerKey === 'z'
    );

    const splitShortcut = (
      commandKey &&
      !event.altKey &&
      !event.shiftKey &&
      lowerKey === 's'
    );

    const deleteSectionShortcut = (
      commandKey &&
      !event.altKey &&
      !event.shiftKey &&
      lowerKey === 'x'
    );

    if (
      undoShortcut &&
      !textEditingTarget
    ) {
      event.preventDefault();
      undoLastChange();
      return;
    }

    if (splitShortcut) {
      // Ctrl/Cmd+S belongs to clip splitting inside Stem Studio,
      // so suppress the browser's Save Page shortcut everywhere.
      event.preventDefault();

      if (!textEditingTarget) {
        splitSelectedSection();
      }

      return;
    }

    if (
      deleteSectionShortcut &&
      !textEditingTarget
    ) {
      event.preventDefault();

      deleteSelectedSection();
      return;
    }

    if (event.key === 'Escape') {
      closeModal(masterBusDialog);
      closeModal(mixSaveDialog);
      closeModal(pluginDirectoryDialog);
      closeModal(addBusDialog);
      closeModal(audioPermissionDialog);
      closeModal(exportAudioDialog);
      closeModal(openProjectDialog);
      closeLoadSongDialog();
      closeModal(newStudioProjectDialog);
      closeTrackRouteMenu();
      closeLibraryCategoryMenu();
      closeTrackContextMenu();
      setTrackLibraryOpen(false);
      setStudioMainMenu(false);
      setSongInfoOpen(false);
      clearArrangementSelection();
      return;
    }

    if (
      event.code === 'Space' &&
      ![
        'INPUT',
        'TEXTAREA',
        'SELECT',
        'BUTTON'
      ].includes(document.activeElement?.tagName)
    ) {
      event.preventDefault();

      if (recordingPendingSave) {
        return;
      }

      if (recordingCountInActive) {
        recordingCountInCancel =
          true;
        return;
      }

      if (recordingActive) {
        stopStudioRecording();
        return;
      }

      if (armedRecordingStem()) {
        startStudioRecording();
        return;
      }

      if (playing) {
        pauseAll();
      } else {
        playAll().catch(() => {});
      }
    }
  });

  if (masterValue) {
    masterValue.textContent = dbText(
      masterVolume?.value || 1
    );
  }

  setReturnLevel('a',Number(auxReturnA?.value || 0.8),false);
  setReturnLevel('b',Number(auxReturnB?.value || 0.7),false);

  stems.forEach(stem => {
    updateReadouts(stem);
    setStemPan(stem,stem.initialPan);
    setStemSend(stem,'a',stem.sends.a,false);
    setStemSend(stem,'b',stem.sends.b,false);
    setTrackTrim(stem,stem.trimDb,false);
    setTrackGroup(stem,stem.group,false);
    renderStemClips(stem);
    renderAutomationLane(stem);
  });

  Object.keys(groupState).forEach(group => {
    updateGroupBus(group,false);
  });

  setSessionTempo(
    sourceTempo,
    false
  );
  setEditSnapMode(
    'grid',
    false
  );
  setPluginRack(false, 348, false);
  resizeTimelineSurface();
  clearLoop();
  updatePlayhead(0);
  updateEqDisplays(true);
  updateGroupMeters(true);
  updateMasterMeter(true);
  updateGains();
  restoreLocalState();
  restoreSelectedMixRef();
  restoreRecordingSettings();

  const requestedExportAudio =
    new URL(
      window.location.href
    ).searchParams.get(
      'export'
    ) === '1';

  if (requestedExportAudio) {
    openExportAudioDialog();

    const cleanExportUrl =
      new URL(
        window.location.href
      );

    cleanExportUrl.searchParams.delete(
      'export'
    );

    window.history.replaceState(
      null,
      '',
      cleanExportUrl.toString()
    );
  }

  const requestedArmStemId =
    Number(
      new URL(
        window.location.href
      ).searchParams.get(
        'arm'
      ) || 0
    );

  if (
    requestedArmStemId > 0 &&
    stemById(
      requestedArmStemId
    )
  ) {
    setArmedStem(
      requestedArmStemId
    );

    markSelectedStem(
      requestedArmStemId
    );

    const cleanUrl =
      new URL(
        window.location.href
      );

    cleanUrl.searchParams.delete(
      'arm'
    );

    window.history.replaceState(
      null,
      '',
      cleanUrl.toString()
    );
  }


  refreshRecordingDevices()
    .then(async devices => {
      const permission =
        await microphonePermissionState();

      if (!devices.length) {
        setRecordingStatus(
          'NO INPUT',
          'error'
        );
      } else {
        setAudioConnectButtonState(
          '',
          permission === 'granted'
            ? 'Connect Audio'
            : 'Connect Audio'
        );
      }
    })
    .catch(error => {
      console.warn(
        'Audio input discovery unavailable.',
        error
      );
    });

  navigator.mediaDevices
    ?.addEventListener?.(
      'devicechange',
      () => {
        refreshRecordingDevices()
          .catch(() => {});
      }
    );

  document.addEventListener(
    'pointerdown',
    event => {
      if (
        trackRoutePopover?.hidden === false &&
        !trackRoutePopover.contains(event.target) &&
        !event.target.closest(
          '[data-track-group-menu]'
        )
      ) {
        closeTrackRouteMenu();
      }

      if (
        trackLibraryCategoryMenu?.hidden === false &&
        !trackLibraryCategoryMenu.contains(
          event.target
        ) &&
        event.target !==
          trackLibraryCategoryButton
      ) {
        closeLibraryCategoryMenu();
      }
    }
  );

  window.addEventListener(
    'resize',
    () => {
      closeTrackContextMenu();
      positionOpenTrackSettings();

      if (
        openRouteStem &&
        trackRoutePopover?.hidden === false
      ) {
        openTrackRouteMenu(openRouteStem);
      }

      if (
        trackLibraryCategoryMenu?.hidden === false
      ) {
        openLibraryCategoryMenu();
      }
    }
  );

  window.addEventListener('pagehide', () => {
    if (localPersistenceReady) {
      window.clearTimeout(localSaveTimer);
      saveLocalStateNow();
    }

    if (
      recordingId &&
      navigator.sendBeacon &&
      projectEndpoint
    ) {
      const form =
        new FormData();

      form.append(
        'csrf_token',
        String(cfg.csrf || '')
      );
      form.append(
        'action',
        'recording_cancel'
      );
      form.append(
        'track_id',
        String(trackId)
      );
      form.append(
        'recording_id',
        recordingId
      );

      try {
        navigator.sendBeacon(
          projectEndpoint,
          form
        );
      } catch (error) {}
    }

    stopRecordingProcessor();
    disconnectRecordingInputGraph(
      true
    );
    pauseAll();
  });


  window.STONEFELLOW_STUDIO_RUNTIME_V87 = {
    ensureAudioGraph,
    getContext:() => context,
    getMasterSource:() => masterLiveMixTap || masterAnalyser,
    getStem:id => stemById(Number(id || 0)),
    getStemCaptureSource:id => {
      const stem = stemById(Number(id || 0));
      return stem?.liveCaptureTap || stem?.analyserNode || null;
    },
    getSelectedStem:() => inspectorSelectedStem(),
    getSelectedStemId:() => Number(inspectorSelectedStem()?.id || 0),
    getPosition:() => globalPosition(),
    getSessionTempo:() => Number(sessionTempo || sourceTempo || 120),
    getSampleRate:() => Number(context?.sampleRate || recordingSampleRate || 48000),
    isPlaying:() => Boolean(playing),
    isCoreRecording:() => Boolean(recordingActive),
    refreshRecordingUi:() => updateRecordingSettingsUi(),
    play:() => playAll(),
    pause:() => pauseAll(),
    setStatus:(text,state='') => setRecordingStatus(text,state),
  };

  /* Stonefellow v90 — complete Studio edit ledger + AI execution bridge. */
  const v90LedgerEndpoint = String(cfg.editLedgerEndpoint || '');
  const v90LedgerSessionKey = sessionStorage.getItem('stonefellow:stem:edit-session') ||
    (crypto.randomUUID?.() || `stem-${Date.now()}-${Math.random().toString(36).slice(2)}`);
  sessionStorage.setItem('stonefellow:stem:edit-session',v90LedgerSessionKey);
  let v90LedgerPendingBefore = null;
  let v90LedgerTimer = 0;
  let v90LedgerSuppress = false;
  let v90AgentBefore = null;
  let v90AgentMeta = {};

  function v90LedgerState() {
    return {
      ...cloneMixState(),
      playhead:Number(position || 0),
      playing:Boolean(playing),
      recording:Boolean(recordingActive),
      selectedStemId:Number(selectedStemId || 0),
      metronome:window.StonefellowMetronomeV91?.getState?.() || {}
    };
  }

  function v90DiffStates(before,after) {
    const changes = [];
    const walk = (a,b,path) => {
      if (JSON.stringify(a) === JSON.stringify(b)) return;
      if (a && b && typeof a === 'object' && typeof b === 'object' && !Array.isArray(a) && !Array.isArray(b)) {
        new Set([...Object.keys(a),...Object.keys(b)]).forEach(key => walk(a[key],b[key],path ? `${path}.${key}` : key));
        return;
      }
      changes.push({path,before:a ?? null,after:b ?? null});
    };
    walk(before,after,'');
    return changes.slice(0,500);
  }

  async function v90PostLedger(before,after,meta = {}) {
    if (!v90LedgerEndpoint || !before || !after) return {changes:[]};
    const changes = v90DiffStates(before,after);
    if (!changes.length) return {changes:[]};
    try {
      await fetch(v90LedgerEndpoint,{
        method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
          action:'record',csrf_token:cfg.csrf,editor_kind:'stem',project_id:Number(trackId),
          session_key:v90LedgerSessionKey,source_kind:meta.source || 'manual',action_key:meta.action || 'studio_edit',
          request_text:meta.request || '',model_provider:meta.provider || '',model_name:meta.model || '',
          conversation_id:Number(meta.conversationId || 0),playhead_seconds:Number(after.playhead || 0),
          before,after,changes
        })
      });
    } catch (error) {
      console.warn('Stem edit ledger:',error);
    }
    return {changes};
  }

  async function v90FlushManualLedger() {
    window.clearTimeout(v90LedgerTimer);
    v90LedgerTimer = 0;
    if (!v90LedgerPendingBefore) return {changes:[]};
    const before = v90LedgerPendingBefore;
    v90LedgerPendingBefore = null;
    return v90PostLedger(before,v90LedgerState(),{source:'manual',action:'studio_edit'});
  }

  function v90QueueManualLedger(before) {
    if (v90LedgerSuppress || !v90LedgerEndpoint || !before) return;
    if (!v90LedgerPendingBefore) v90LedgerPendingBefore = { ...cloneMixState(before), playhead:Number(position || 0), playing:Boolean(playing), recording:Boolean(recordingActive), selectedStemId:Number(selectedStemId || 0) };
    window.clearTimeout(v90LedgerTimer);
    v90LedgerTimer = window.setTimeout(() => { void v90FlushManualLedger(); },650);
  }

  function v90GetAgentState() {
    return {
      tempo:Number(sessionTempo || 120),selected_id:Number(selectedStemId || 0),playing:Boolean(playing),
      live_mix:Boolean(window.StonefellowStemLiveRecordingV87?.masterEnabled?.()),
      stems:stems.map(stem => ({
        id:Number(stem.id),name:String(stem.name || stem.label || `Stem ${stem.id}`),role:String(stem.role || ''),
        muted:Boolean(stem.muted),solo:Boolean(stem.solo),volume:Number(stem.userGain || 0),pan:Number(stem.pan?.value || 0),
        trim:Number(stem.trimDb || 0),send_a:Number(stem.sends?.a || 0),send_b:Number(stem.sends?.b || 0),route:String(stem.group || 'direct'),
        plugins:(stem.plugins || []).map((plugin,index)=>({index,type:plugin.type,enabled:plugin.enabled !== false,params:{...plugin.params}})),
        automation:normalizeAutomation(stem.automation)
      }))
    };
  }

  function v90PluginForCommand(stem,command) {
    const target = stem ? pluginTargetByKey(stem.pluginKey) : null;
    if (!target) return [null,null,-1];
    let index = Number.isInteger(Number(command.plugin_index)) ? Number(command.plugin_index) : -1;
    if (index < 0 || !target.plugins?.[index]) {
      const type = String(command.plugin_type || command.plugin || '').toLowerCase();
      index = target.plugins?.findIndex(plugin => String(plugin.type).toLowerCase() === type) ?? -1;
    }
    return [target,index >= 0 ? target.plugins[index] : null,index];
  }

  async function v90ExecuteAgentCommand(command) {
    const type = String(command?.type || '');
    const stem = command?.stem_id ? stemById(Number(command.stem_id)) : null;
    if (type === 'track_trim' && stem) { setTrackTrim(stem,clamp(command.value,-24,24)); return {status:'success',result:'Track trim updated'}; }
    if (type === 'send' && stem) { setStemSend(stem,command.bus === 'b' ? 'b' : 'a',clamp(command.value,0,1)); return {status:'success',result:`AUX ${String(command.bus || 'a').toUpperCase()} send updated`}; }
    if (type === 'route' && stem) { setTrackGroup(stem,String(command.route || 'direct')); return {status:'success',result:'Track route updated'}; }
    if (type === 'plugin_param' && stem) {
      const [target,plugin,index] = v90PluginForCommand(stem,command);
      const param = String(command.param || '');
      if (!target || !plugin || !Object.prototype.hasOwnProperty.call(plugin.params || {},param)) return {status:'failed',result:'Plugin parameter not found'};
      plugin.params[param] = Number(command.value);
      updateTrackPluginAudio(plugin);
      if (context) rebuildPluginTargetGraph(target);
      renderPluginTargetList(target);
      if (pluginTargetKey === (target.pluginKey || target.key) && pluginEditIndex === index) openPluginEditor(target.pluginKey || target.key,index);
      scheduleLocalSave(0);
      return {status:'success',result:`${plugin.type}.${param} updated`};
    }
    if (type === 'plugin_bypass' && stem) {
      const [target,plugin,index] = v90PluginForCommand(stem,command);
      if (!target || !plugin) return {status:'failed',result:'Plugin not found'};
      plugin.enabled = !Boolean(command.bypassed);
      renderPluginTargetList(target);
      if (context) rebuildPluginTargetGraph(target);
      if (pluginTargetKey === (target.pluginKey || target.key) && pluginEditIndex === index) openPluginEditor(target.pluginKey || target.key,index);
      scheduleLocalSave(0);
      return {status:'success',result:plugin.enabled ? 'Plugin enabled' : 'Plugin bypassed'};
    }
    if (type === 'master_volume') {
      if (!masterVolume) return {status:'failed',result:'Master fader unavailable'};
      masterVolume.value = String(clamp(command.value,0,1.5));
      masterVolume.dispatchEvent(new Event('input',{bubbles:true}));
      return {status:'success',result:'Master volume updated'};
    }
    if (type === 'bus_volume') {
      const group = ['vocals','rhythm','music'].includes(String(command.bus)) ? String(command.bus) : '';
      const input = group ? document.querySelector(`[data-group-volume="${group}"]`) : null;
      if (!input) return {status:'failed',result:'Bus fader unavailable'};
      input.value = String(clamp(command.value,0,1.5));input.dispatchEvent(new Event('input',{bubbles:true}));
      return {status:'success',result:`${group} bus volume updated`};
    }
    if (type === 'bus_mute') {
      const group = ['vocals','rhythm','music'].includes(String(command.bus)) ? String(command.bus) : '';
      const button = group ? document.querySelector(`[data-group-mute="${group}"]`) : null;
      if (!button) return {status:'failed',result:'Bus mute unavailable'};
      const active = button.classList.contains('active') || button.getAttribute('aria-pressed') === 'true';
      if (active !== Boolean(command.value)) button.click();
      return {status:'success',result:`${group} bus mute updated`};
    }
    if (type === 'automation_point' && stem) {
      const parameter = ['volume','pan','auxA','auxB'].includes(String(command.parameter)) ? String(command.parameter) : 'volume';
      const spec = automationSpec(parameter);
      const point = {t:clamp(command.time,0,duration),v:clamp(command.value,spec.min,spec.max)};
      stem.automation[parameter] = [...(stem.automation[parameter] || []),point].sort((a,b)=>a.t-b.t).slice(0,200);
      if (stem.automationParameter) stem.automationParameter.value = parameter;
      stem.automationOpen = true;renderAutomationLane(stem);scheduleLocalSave(0);
      return {status:'success',result:`${parameter} automation point added`};
    }
    return null;
  }

  window.StonefellowStemStudioV90 = {
    getAgentState:v90GetAgentState,
    sessionKey:v90LedgerSessionKey,
    executeAgentCommand:v90ExecuteAgentCommand,
    beginAgentEdit(meta={}) { void v90FlushManualLedger(); v90LedgerSuppress=true; v90AgentBefore=v90LedgerState(); v90AgentMeta={...meta}; },
    async endAgentEdit(meta={}) { const before=v90AgentBefore;v90AgentBefore=null;v90LedgerSuppress=false;const merged={...v90AgentMeta,...meta,source:'agent',action:'agent_plan'};v90AgentMeta={};return before ? v90PostLedger(before,v90LedgerState(),merged) : {changes:[]}; },
    cancelAgentEdit() { v90AgentBefore=null;v90AgentMeta={};v90LedgerSuppress=false; },
    flushLedger:v90FlushManualLedger
  };


  /* Stonefellow v91 — complete user-action capability bridge. */
  function v91ControlManifest() {
    let generated = 0;
    const controls = [];
    document.querySelectorAll('button,input,select,textarea,a[href]').forEach(el => {
      if (el.closest('.editor-agent-panel,.team-chat-widget')) return;
      if (el.type === 'hidden') return;
      if (!el.dataset.agentControlId) {
        const seed = String(el.id || el.dataset.stemId || el.dataset.trackId || el.dataset.pluginType || el.getAttribute('aria-label') || el.title || el.textContent || `control-${++generated}`)
          .trim().toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-|-$/g,'').slice(0,90) || `control-${++generated}`;
        el.dataset.agentControlId = `${seed}-${generated++}`;
      }
      const labelNode = el.closest('label');
      const label = String(el.getAttribute('aria-label') || el.title || labelNode?.querySelector('span,strong')?.textContent || el.textContent || el.name || el.id || el.dataset.agentControlId).trim().replace(/\s+/g,' ').slice(0,220);
      let kind = 'button';
      if (el.tagName === 'SELECT') kind='select';
      else if (el.tagName === 'TEXTAREA') kind='text';
      else if (el.tagName === 'INPUT') kind = el.type === 'range' ? 'range' : el.type === 'number' ? 'number' : el.type === 'checkbox' ? 'checkbox' : el.type === 'file' ? 'file' : 'text';
      const options = el.tagName === 'SELECT' ? [...el.options].slice(0,80).map(o=>({value:String(o.value),label:String(o.textContent||'').trim()})) : [];
      controls.push({id:el.dataset.agentControlId,kind,label,value:'value' in el?String(el.value??''):'',checked:Boolean(el.checked),pressed:el.getAttribute('aria-pressed')==='true'||el.classList.contains('active'),disabled:Boolean(el.disabled),options});
    });
    return controls.slice(0,360);
  }

  function v91ClipRecords() {
    const rows=[];
    stems.forEach(stem => (stem.clips || []).forEach(clip => rows.push({id:String(clip.id),kind:'stem',stem_id:Number(stem.id),name:String(stem.name||stem.label||clip.id),start:Number(clip.timelineStart||0),duration:Number(clip.timelineLength||0),source_start:Number(clip.sourceStart||0),source_end:Number(clip.sourceEnd||0),gain_db:Number(clip.gainDb||0),fade_in:Number(clip.fadeIn||0),fade_out:Number(clip.fadeOut||0),muted:Boolean(clip.muted)})));
    libraryClips.forEach(clip => rows.push({id:String(clip.id),kind:'library',stem_id:Number(clip.stemId||0),name:String(clip.name||clip.id),start:Number(clip.timelineStart||0),duration:Number(clip.timelineLength||0),source_start:Number(clip.sourceStart||0),source_end:Number(clip.sourceEnd||0),gain_db:Number(clip.gainDb||0),fade_in:Number(clip.fadeIn||0),fade_out:Number(clip.fadeOut||0),muted:Boolean(clip.muted)}));
    return rows;
  }

  function v91SelectedClipId() {
    const selected=selectedArrangementData?.();
    return String(selected?.clip?.id || '');
  }

  function v91GetAgentState() {
    const base=v90GetAgentState();
    return {...base,
      clips:v91ClipRecords(),selected_clip_id:v91SelectedClipId(),controls:v91ControlManifest(),
      buses:[...Object.entries(groupState).map(([key,bus])=>({key,name:key,volume:Number(bus.volume||1),muted:Boolean(bus.muted)})),...customBuses.map(bus=>({key:String(bus.key||''),name:String(bus.name||bus.label||bus.key||''),volume:Number(bus.volume||1),muted:Boolean(bus.muted)}))],
      master:{volume:Number(masterVolume?.value||1),aux_a:Number(auxReturnA?.value||0),aux_b:Number(auxReturnB?.value||0),plugins:(fixedPluginTargets.master.plugins||[]).map((p,index)=>({index,type:p.type,enabled:p.enabled!==false,params:{...p.params}}))},
      loop:{active:Boolean(loopActive),start:Number(loopStart||0),end:Number(loopEnd||0)},markers:timelineMarkers.map(x=>({time:Number(x.time||0),label:String(x.label||'')})),regions:timelineRegions.map(x=>({start:Number(x.start||0),end:Number(x.end||0),label:String(x.label||''),note:String(x.note||''),note_id:Number(x.noteId||0),author:String(x.author||''),shared:Boolean(x.shared)})),zoom:Number(timelineZoom||1),snap:String(editSnapMode||'grid'),recording:Boolean(recordingActive),monitoring:Boolean(recordingMonitorEnabled),metronome:window.StonefellowMetronomeV91?.getState?.()||{}
    };
  }

  function v91Control(id) { return document.querySelector(`[data-agent-control-id="${CSS.escape(String(id||''))}"]`); }
  function v91Clip(id) {
    const key=String(id||'');
    for (const stem of stems) { const clip=(stem.clips||[]).find(x=>String(x.id)===key); if(clip)return{kind:'stem',stem,clip}; }
    const clip=libraryClips.find(x=>String(x.id)===key); return clip?{kind:'library',clip}:null;
  }
  function v91RefreshClip(found) { if(!found)return;if(found.kind==='stem'){found.stem.activeClipId='';renderStemClips(found.stem);}else updateLibraryClipDom(found.clip);scheduleLocalSave(0); }
  function v91SelectClip(found) { if(!found)return false;if(found.kind==='stem'){const el=found.stem.mainClipLayer?.querySelector(`[data-main-clip-id="${CSS.escape(String(found.clip.id))}"]`);selectArrangementClip('stem',found.stem.id,found.clip.id,el||null);}else selectArrangementClip('library',found.clip.id,found.clip.id,found.clip.clipElement||null);return true; }

  async function v91ExecuteAgentCommand(command) {
    const legacy=await v90ExecuteAgentCommand(command); if(legacy)return legacy;
    const type=String(command?.type||'');
    if(['ui_click','ui_set','ui_select','ui_toggle'].includes(type)){
      v91ControlManifest(); const el=v91Control(command.control_id); if(!el||el.disabled)return{status:'failed',result:'Studio control unavailable'};
      if(type==='ui_click'){el.click();return{status:'success',result:`${String(command.control_id)} activated`};}
      if(type==='ui_toggle'){const next=Boolean(command.value);if(el.type==='checkbox'){el.checked=next;el.dispatchEvent(new Event('change',{bubbles:true}));}else{const active=el.getAttribute('aria-pressed')==='true'||el.classList.contains('active');if(active!==next)el.click();}return{status:'success',result:'Studio toggle updated'};}
      el.value=String(command.value??'');el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));return{status:'success',result:'Studio control updated'};
    }
    if(type.startsWith('clip_')){
      const found=v91Clip(command.clip_id);if(!found)return{status:'failed',result:'Clip not found'};const c=found.clip;
      if(type==='clip_move'){c.timelineStart=clamp(Number(command.start||0),0,Math.max(0,duration-Number(c.timelineLength||0)));v91RefreshClip(found);return{status:'success',result:'Clip moved'};}
      if(type==='clip_gain'){c.gainDb=clamp(Number(command.value||0),-48,24);v91RefreshClip(found);return{status:'success',result:'Clip gain updated'};}
      if(type==='clip_fade'){if(command.edge==='out')c.fadeOut=clamp(Number(command.value||0),0,60);else c.fadeIn=clamp(Number(command.value||0),0,60);v91RefreshClip(found);return{status:'success',result:'Clip fade updated'};}
      if(type==='clip_mute'){c.muted=Boolean(command.value);v91RefreshClip(found);return{status:'success',result:'Clip mute updated'};}
      if(type==='clip_trim'){
        const start=Number(c.timelineStart||0),length=Math.max(.05,Number(c.timelineLength||.05)),end=start+length,target=clamp(Number(command.time||0),0,duration);
        const ratio=found.kind==='stem'?Math.max(.0001,Number(found.stem.timelineRatio||1)):Math.max(.0001,libraryClipSourceRatio(c));
        if(command.edge==='left'){const next=clamp(target,0,end-.05),delta=next-start;c.timelineStart=next;c.timelineLength=end-next;c.sourceStart=clamp(Number(c.sourceStart||0)+delta/ratio,0,Math.max(0,Number(c.sourceEnd||0)-.02));}
        else{const next=clamp(target,start+.05,duration);c.timelineLength=next-start;c.sourceEnd=Math.max(Number(c.sourceStart||0)+.02,Number(c.sourceStart||0)+c.timelineLength/ratio);}
        v91RefreshClip(found);return{status:'success',result:'Clip trimmed'};
      }
      if(type==='clip_split'){v91SelectClip(found);return splitSelectedSection()?{status:'success',result:'Clip split at playhead'}:{status:'failed',result:'Playhead is not inside the selected clip'};}
      if(type==='clip_delete'){v91SelectClip(found);return deleteSelectedSection()?{status:'success',result:'Clip deleted'}:{status:'failed',result:'Clip could not be deleted'};}
    }
    if(type==='loop_set'){loopStart=clamp(Number(command.start||0),0,duration);loopEnd=clamp(Number(command.end||0),loopStart+.01,duration);loopActive=true;updateLoopOverlay();updateLoopButtons();scheduleLocalSave(0);return{status:'success',result:'Loop range set'};}
    if(type==='loop_clear'){clearLoop();scheduleLocalSave(0);return{status:'success',result:'Loop cleared'};}
    if(type==='marker_add'){addMarkerAt(Number(command.time||globalPosition()),String(command.label||'Marker'));return{status:'success',result:'Marker added'};}
    if(type==='region_add'){const start=Number(command.start||0),end=Number(command.end||0),note=String(command.label||'Production note');const saved=await shareRegionNote(start,end,note);addRegionAt(Number(saved.start??start),Number(saved.end??end),String(saved.label||note),saved);return{status:'success',result:'REGION note shared in Agent Chat'};}
    if(type==='automation_clear'){const stem=stemById(Number(command.stem_id||0));if(!stem)return{status:'failed',result:'Track not found'};const p=String(command.parameter||'volume');stem.automation[p]=[];renderAutomationLane(stem);restoreStaticAutomationTargets();scheduleLocalSave(0);return{status:'success',result:`${p} automation cleared`};}
    if(type==='automation_delete'){const stem=stemById(Number(command.stem_id||0));if(!stem)return{status:'failed',result:'Track not found'};const p=String(command.parameter||'volume'),points=stem.automation[p]||[],i=Number(command.index||0);if(!points[i])return{status:'failed',result:'Automation point not found'};points.splice(i,1);renderAutomationLane(stem);scheduleLocalSave(0);return{status:'success',result:'Automation point deleted'};}
    if(type==='plugin_remove'){const stem=stemById(Number(command.stem_id||0)),target=stem?pluginTargetByKey(stem.pluginKey):null,i=Number(command.plugin_index||0);if(!target?.plugins?.[i])return{status:'failed',result:'Plugin not found'};target.plugins.splice(i,1);renderPluginTargetList(target);if(context)rebuildPluginTargetGraph(target);scheduleLocalSave(0);return{status:'success',result:'Plugin removed'};}
    if(type==='aux_return'){setReturnLevel(command.bus==='b'?'b':'a',clamp(Number(command.value||0),0,1.5));return{status:'success',result:'Aux return updated'};}
    if(type==='reset_mix'){resetMix();return{status:'success',result:'Mix reset'};}
    if(type==='zoom'){setTimelineZoom(Number(command.value||1));return{status:'success',result:'Timeline zoom updated'};}
    if(type==='snap'){setEditSnapMode(String(command.value||'grid'));return{status:'success',result:'Timeline snap updated'};}
    if(type==='metronome'){window.StonefellowMetronomeV91?.configure?.(command,{manual:false});if([0,1,2,4].includes(Number(command.count_in))){setRecordingCountInBarsValue(Number(command.count_in));}return{status:'success',result:'Metronome updated'};}
    return null;
  }

  window.StonefellowStemStudioV91={
    ...window.StonefellowStemStudioV90,
    getAgentState:v91GetAgentState,
    executeAgentCommand:v91ExecuteAgentCommand,
    getLedgerState:v90LedgerState,
    recordManualEdit:(before,meta={})=>v90PostLedger(before,v90LedgerState(),{source:'manual',action:'studio_edit',...meta})
  };

})();
