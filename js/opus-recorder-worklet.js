class SpechRecorderProcessor extends AudioWorkletProcessor {
    process(inputs) {
        const channels = inputs[0];
        if (!channels || !channels[0]) return true;
        const count = channels.length >= 2 ? 2 : 1;
        const samples = channels[0].length;
        const pcm = new Int16Array(samples * count);
        for (let frame = 0; frame < samples; frame++) {
            for (let channel = 0; channel < count; channel++) {
                const clamped = Math.max(-1, Math.min(1, channels[channel][frame]));
                pcm[frame * count + channel] = (clamped * 32767) | 0;
            }
        }
        this.port.postMessage({buffer: pcm.buffer, channels: count}, [pcm.buffer]);
        return true;
    }
}

registerProcessor('recorder', SpechRecorderProcessor);
