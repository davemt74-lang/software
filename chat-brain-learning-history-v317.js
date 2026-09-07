(() => {
  'use strict';
  // Brain Learning history is intentionally retained as backend/audit data but
  // hidden from the Activity Center until production usage gives us enough
  // real prioritization history to design the explainability view well.
  window.VP3_BRAIN_LEARNING_HISTORY = Object.freeze({ enabled:false, build:'v317-hidden-20260907' });
})();
