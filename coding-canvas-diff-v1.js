(() => {
  'use strict';
  // Saved Canvas sources are bounded by the native artifact contract. Keep large
  // rewrites linear rather than allocating an unbounded line-comparison table.
  const maxCells = 400000;
  function compare(before,after) {
    if (typeof before !== 'string' || typeof after !== 'string' || before.length > 60000 || after.length > 60000) {
      throw new RangeError('This source is too large for version comparison.');
    }
    const left = before === '' ? [] : before.split('\n');
    const right = after === '' ? [] : after.split('\n');
    let head = 0,tail = 0;
    while (head < left.length && head < right.length && left[head] === right[head]) head++;
    while (tail < left.length-head && tail < right.length-head && left[left.length-1-tail] === right[right.length-1-tail]) tail++;
    const a = left.slice(head,left.length-tail),b = right.slice(head,right.length-tail);
    const chunks = [];
    let added = 0,removed = 0;
    function append(type,line) {
      let last = chunks.at(-1);
      if (last?.type !== type) { last = {type,lines:[]}; chunks.push(last); }
      last.lines.push(line);
      if (type === 'add') added++;
      if (type === 'remove') removed++;
    }
    for (let i=0;i<head;i++) append('equal',left[i]);
    const coarse = (a.length+1)*(b.length+1) > maxCells;
    if (coarse) {
      for (const line of a) append('remove',line);
      for (const line of b) append('add',line);
    } else {
      const columns = b.length+1;
      const table = new Uint16Array((a.length+1)*columns);
      for (let i=a.length-1;i>=0;i--) {
        for (let j=b.length-1;j>=0;j--) {
          table[i*columns+j] = a[i] === b[j] ? table[(i+1)*columns+j+1]+1 : Math.max(table[(i+1)*columns+j],table[i*columns+j+1]);
        }
      }
      let i=0,j=0;
      while (i<a.length || j<b.length) {
        if (i<a.length && j<b.length && a[i] === b[j]) { append('equal',a[i]); i++; j++; }
        else if (i<a.length && (j===b.length || table[(i+1)*columns+j]>=table[i*columns+j+1])) append('remove',a[i++]);
        else append('add',b[j++]);
      }
    }
    for (let i=left.length-tail;i<left.length;i++) append('equal',left[i]);
    return {chunks,added,removed,coarse};
  }
  window.VP3CanvasDiffV1 = Object.freeze({compare});
})();
