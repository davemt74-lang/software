/* One transient PNG shared between the service worker and its preview page. */
const VP3ScreenshotStore = (() => {
  async function open() {
    return new Promise((resolve,reject) => {
      const request = indexedDB.open('vp3-local-screenshot',1);
      request.onupgradeneeded = () => request.result.createObjectStore('images',{keyPath:'id'});
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  async function transaction(write, action) {
    const db = await open();
    try {
      return await new Promise((resolve,reject) => {
        const tx = db.transaction('images',write?'readwrite':'readonly');
        let value;
        const request = action(tx.objectStore('images'));
        if (request) request.onsuccess = () => {value=request.result;};
        tx.oncomplete = () => resolve(value);
        tx.onabort = tx.onerror = () => reject(tx.error || new Error('Local preview storage failed.'));
      });
    } finally {db.close();}
  }
  return {
    clear: () => transaction(true,store => store.clear()),
    save: value => transaction(true,store => {store.clear();return store.put({...value,expires:Date.now()+600000});}),
    async take(id) {
      const value = await transaction(true,store => {const request=store.get(id);request.addEventListener('success',()=>store.delete(id));return request;});
      return value?.expires > Date.now() ? value : null;
    }
  };
})();
