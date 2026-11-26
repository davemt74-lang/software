/* Read only the call streams connected after consent. The output is silent. */
class VP3OperatorRemoteCaptureV1 extends AudioWorkletProcessor {
  constructor(options) {
    super();
    this.channels = options?.processorOptions?.channels === 2 ? 2 : 1;
    this.frame = new DataView(new ArrayBuffer(128000 * this.channels));
    this.sums = new Float64Array(this.channels);
    this.enabled = false;
    this.reset();
    this.port.onmessage = event => {
      this.enabled = event.data === 'start';
      this.reset();
    };
  }

  reset() {
    this.bytes = 0;
    this.weight = 0;
    this.sums.fill(0);
  }

  process(inputs, outputs) {
    for (const output of outputs) for (const channel of output) channel.fill(0);
    const remote = inputs[0]?.[0];
    if (!this.enabled || !remote || sampleRate < 16000 || sampleRate > 96000) return true;
    const ratio = sampleRate / 16000;
    for (let i = 0; i < remote.length; i++) {
      let remaining = 1;
      while (remaining > 1e-9) {
        const take = Math.min(remaining, ratio - this.weight);
        for (let ch = 0; ch < this.channels; ch++) {
          const value = inputs[ch]?.[0]?.[i];
          if (Number.isFinite(value)) this.sums[ch] += value * take;
        }
        this.weight += take;
        remaining -= take;
        if (this.weight >= ratio - 1e-9) {
          for (let ch = 0; ch < this.channels; ch++) {
            const value = Math.max(-1, Math.min(1, this.sums[ch] / ratio));
            this.frame.setInt16(this.bytes, Math.round(value * (value < 0 ? 32768 : 32767)), true);
            this.bytes += 2;
          }
          this.sums.fill(0);
          this.weight = 0;
          if (this.bytes === this.frame.byteLength) {
            const frame = this.frame.buffer;
            this.port.postMessage(frame, [frame]);
            this.frame = new DataView(new ArrayBuffer(128000 * this.channels));
            this.bytes = 0;
          }
        }
      }
    }
    return true;
  }
}
registerProcessor('vp3-operator-remote-capture-v1', VP3OperatorRemoteCaptureV1);
