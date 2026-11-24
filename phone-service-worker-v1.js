/* VP3 Phone installation foundation. No fetch interception, caches, background sensors or push authorization. */
'use strict';
self.addEventListener('install', event => { event.waitUntil(self.skipWaiting()); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });
// Push handling belongs to the separately authorized subscription and delivery phase.
