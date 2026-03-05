// =============================================================
//  CatarataDAW — Synchronized Audio Player (Web Audio API)
// =============================================================

class DAWPlayer {
    constructor(clips, maxTime) {
        this.clips       = clips;       // [{clip_id, start_time, duration, file_path}, ...]
        this.maxTime     = maxTime;
        this.audioCtx    = null;
        this.buffers     = new Map();   // clip_id → AudioBuffer
        this.sources     = [];          // active AudioBufferSourceNode[]
        this.state       = 'stopped';   // stopped | playing | paused
        this.startedAt   = 0;           // audioCtx.currentTime when playback started
        this.pausedAt    = 0;           // how far into the timeline we paused
        this.rafId       = null;
        this.loaded      = false;

        // DOM
        this.playBtn     = document.getElementById('daw-play');
        this.pauseBtn    = document.getElementById('daw-pause');
        this.stopBtn     = document.getElementById('daw-stop');
        this.timeDisplay = document.getElementById('daw-time');
        this.playhead    = document.getElementById('daw-playhead');
        this.loadStatus  = document.getElementById('daw-load-status');
        this.progressBar = document.getElementById('daw-progress');

        this._bindEvents();
        this._initAudio();
    }

    // ── Init AudioContext & load all files ────────────────────
    async _initAudio() {
        this.audioCtx = new (window.AudioContext || window.webkitAudioContext)();

        const withAudio = this.clips.filter(c => c.file_path);
        if (withAudio.length === 0) {
            this._setLoadStatus('✅ Ready (no audio files to load)', 100);
            this.loaded = true;
            return;
        }

        this._setLoadStatus(`Loading 0/${withAudio.length} clips...`, 0);
        let done = 0;

        const promises = withAudio.map(async clip => {
            try {
                const resp   = await fetch(clip.file_path);
                const arrBuf = await resp.arrayBuffer();
                const audioBuf = await this.audioCtx.decodeAudioData(arrBuf);
                this.buffers.set(clip.clip_id, audioBuf);
            } catch (err) {
                console.warn(`Failed to load clip #${clip.clip_id}:`, err);
            }
            done++;
            const pct = Math.round(done / withAudio.length * 100);
            this._setLoadStatus(`Loading ${done}/${withAudio.length} clips...`, pct);
        });

        await Promise.all(promises);
        this.loaded = true;
        this._setLoadStatus(`✅ ${this.buffers.size}/${withAudio.length} clips loaded — Ready to play`, 100);
    }

    _setLoadStatus(text, pct) {
        if (this.loadStatus) this.loadStatus.textContent = text;
        if (this.progressBar) this.progressBar.style.width = pct + '%';
    }

    // ── Bind button events ────────────────────────────────────
    _bindEvents() {
        this.playBtn?.addEventListener('click',  () => this.play());
        this.pauseBtn?.addEventListener('click', () => this.pause());
        this.stopBtn?.addEventListener('click',  () => this.stop());

        // Click on timeline to seek
        const timelineTrack = document.getElementById('daw-timeline-track');
        timelineTrack?.addEventListener('click', (e) => {
            if (this.state === 'playing') this.stop();
            const rect = timelineTrack.getBoundingClientRect();
            const pct  = (e.clientX - rect.left) / rect.width;
            this.pausedAt = pct * this.maxTime;
            this._updateUI(this.pausedAt);
            this.play();
        });
    }

    // ── Play ──────────────────────────────────────────────────
    play() {
        if (!this.loaded) return;
        if (this.state === 'playing') return;

        // Resume suspended context (browser autoplay policy)
        if (this.audioCtx.state === 'suspended') this.audioCtx.resume();

        const offset = this.pausedAt;

        // Schedule each clip
        this.clips.forEach(clip => {
            const buf = this.buffers.get(clip.clip_id);
            if (!buf) return;

            const clipEnd = clip.start_time + clip.duration;

            // Skip clips that are entirely before our current offset
            if (clipEnd <= offset) return;

            const source  = this.audioCtx.createBufferSource();
            source.buffer = buf;
            source.connect(this.audioCtx.destination);

            if (offset > clip.start_time) {
                // We're partway through this clip — start from the middle
                const clipOffset = offset - clip.start_time;
                const remaining  = clip.duration - clipOffset;
                source.start(0, clipOffset, remaining);
            } else {
                // Clip hasn't started yet — schedule it in the future
                const delay = clip.start_time - offset;
                source.start(this.audioCtx.currentTime + delay, 0, clip.duration);
            }

            this.sources.push(source);
        });

        this.startedAt = this.audioCtx.currentTime - offset;
        this.state     = 'playing';
        this._updateButtons();
        this._tick();
    }

    // ── Pause ─────────────────────────────────────────────────
    pause() {
        if (this.state !== 'playing') return;

        this.pausedAt = this.audioCtx.currentTime - this.startedAt;
        this._stopAllSources();
        this.state = 'paused';
        this._updateButtons();
        cancelAnimationFrame(this.rafId);
    }

    // ── Stop ──────────────────────────────────────────────────
    stop() {
        this._stopAllSources();
        this.pausedAt = 0;
        this.state    = 'stopped';
        this._updateButtons();
        this._updateUI(0);
        cancelAnimationFrame(this.rafId);
    }

    _stopAllSources() {
        this.sources.forEach(s => { try { s.stop(); } catch(e) {} });
        this.sources = [];
    }

    // ── Animation loop ────────────────────────────────────────
    _tick() {
        if (this.state !== 'playing') return;

        const current = this.audioCtx.currentTime - this.startedAt;

        if (current >= this.maxTime) {
            this.stop();
            return;
        }

        this._updateUI(current);
        this.rafId = requestAnimationFrame(() => this._tick());
    }

    // ── Update UI ─────────────────────────────────────────────
    _updateUI(currentTime) {
        const pct = Math.min(currentTime / this.maxTime * 100, 100);

        // Playhead position
        if (this.playhead) this.playhead.style.left = pct + '%';

        // Time display
        if (this.timeDisplay) {
            const mins = Math.floor(currentTime / 60);
            const secs = (currentTime % 60).toFixed(2);
            const total_mins = Math.floor(this.maxTime / 60);
            const total_secs = (this.maxTime % 60).toFixed(2);
            this.timeDisplay.textContent =
                `${String(mins).padStart(2,'0')}:${secs.padStart(5,'0')} / ${String(total_mins).padStart(2,'0')}:${total_secs.padStart(5,'0')}`;
        }

        // Highlight active clips
        document.querySelectorAll('.timeline-clip').forEach(el => {
            const start = parseFloat(el.dataset.start);
            const end   = parseFloat(el.dataset.end);
            if (currentTime >= start && currentTime <= end) {
                el.classList.add('clip-active');
            } else {
                el.classList.remove('clip-active');
            }
        });
    }

    _updateButtons() {
        if (this.playBtn)  this.playBtn.disabled  = (this.state === 'playing');
        if (this.pauseBtn) this.pauseBtn.disabled = (this.state !== 'playing');
        if (this.stopBtn)  this.stopBtn.disabled  = (this.state === 'stopped');

        // Visual states
        [this.playBtn, this.pauseBtn, this.stopBtn].forEach(b => b?.classList.remove('transport-active'));
        if (this.state === 'playing' && this.pauseBtn) this.pauseBtn.classList.add('transport-active');
        if (this.state === 'paused'  && this.playBtn)  this.playBtn.classList.add('transport-active');
    }
}

// Auto-init when clip data is available
document.addEventListener('DOMContentLoaded', () => {
    if (typeof dawClipData !== 'undefined' && dawClipData.length > 0) {
        window.dawPlayer = new DAWPlayer(dawClipData, dawMaxTime);
    }
});
