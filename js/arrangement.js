// =============================================================
//  CatarataDAW — Arrangement View JS
//  Features: drag-drop clips, synchronized playback, right-click
//  to add samples, mute tracks, Web Audio API mixing
// =============================================================

// ── State ─────────────────────────────────────────────────────
let audioCtx   = null;
let buffers    = new Map();   // clip_id → AudioBuffer
let sources    = [];          // active AudioBufferSourceNode[]
let gainNodes  = new Map();   // track_id → GainNode (for muting)
let mutedTracks= new Set();
let playState  = 'stopped';   // stopped | playing | paused
let startedAt  = 0;
let pausedAt   = 0;
let rafId      = null;

// Drag state
let dragClipId   = null;
let dragOffsetPct= 0;

// Right-click target track
let ctxTrackId    = null;
let ctxStartTime  = 0;

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initAudio();
    bindTransport();
    bindGridRightClick();
    syncScroll();
});

// ── Audio Context & Buffer loading ────────────────────────────
async function initAudio() {
    audioCtx = new (window.AudioContext || window.webkitAudioContext)();

    const allClips = arrData.flatMap(t => t.clips.map(c => ({ ...c, track_id: t.track_id })));
    const withFile = allClips.filter(c => c.file_path);

    if (withFile.length === 0) {
        setLoadStatus('✅ Ready (no audio files)', 100); return;
    }

    setLoadStatus(`Loading 0/${withFile.length}...`, 0);
    let done = 0;

    await Promise.all(withFile.map(async clip => {
        try {
            const res  = await fetch(clip.file_path);
            const buf  = await res.arrayBuffer();
            const aBuf = await audioCtx.decodeAudioData(buf);
            buffers.set(clip.clip_id, aBuf);
        } catch (e) { /* file may not exist yet */ }
        done++;
        setLoadStatus(`Loading ${done}/${withFile.length}...`, Math.round(done / withFile.length * 100));
    }));

    setLoadStatus(`✅ ${buffers.size}/${withFile.length} samples loaded`, 100);
}

function setLoadStatus(text, pct) {
    const el = document.getElementById('arr-load-status');
    const pb = document.getElementById('arr-progress');
    if (el) el.textContent = text;
    if (pb) pb.style.width = pct + '%';
}

// ── Transport ─────────────────────────────────────────────────
function bindTransport() {
    document.getElementById('arr-play') ?.addEventListener('click', arrPlay);
    document.getElementById('arr-pause')?.addEventListener('click', arrPause);
    document.getElementById('arr-stop') ?.addEventListener('click', arrStop);

    // Click ruler / grid to seek
    document.getElementById('arr-ruler')?.addEventListener('click', e => {
        const rect = e.currentTarget.getBoundingClientRect();
        const pct  = (e.clientX - rect.left) / rect.width;
        const time = pct * arrMaxTime;
        arrStop();
        pausedAt = Math.max(0, time);
        arrUpdateUI(pausedAt);
        arrPlay();
    });
}

function arrPlay() {
    if (playState === 'playing') return;
    if (audioCtx.state === 'suspended') audioCtx.resume();

    const offset = pausedAt;
    const allClips = arrData.flatMap(t => t.clips.map(c => ({ ...c, track_id: t.track_id })));

    allClips.forEach(clip => {
        const buf = buffers.get(clip.clip_id);
        if (!buf) return;
        if (mutedTracks.has(clip.track_id)) return;

        const clipEnd = clip.start_time + clip.duration;
        if (clipEnd <= offset) return;

        const gain = audioCtx.createGain();
        gain.gain.value = 1;
        gain.connect(audioCtx.destination);
        gainNodes.set(clip.clip_id, gain);

        const src = audioCtx.createBufferSource();
        src.buffer = buf;
        src.connect(gain);

        if (offset > clip.start_time) {
            const clipOffset = offset - clip.start_time;
            src.start(0, clipOffset, clip.duration - clipOffset);
        } else {
            src.start(audioCtx.currentTime + (clip.start_time - offset), 0, clip.duration);
        }
        sources.push(src);
    });

    startedAt = audioCtx.currentTime - offset;
    playState = 'playing';
    updateTransportBtns();
    arrTick();
}

function arrPause() {
    if (playState !== 'playing') return;
    pausedAt = audioCtx.currentTime - startedAt;
    stopAllSources();
    playState = 'paused';
    updateTransportBtns();
    cancelAnimationFrame(rafId);
}

function arrStop() {
    stopAllSources();
    pausedAt  = 0;
    playState = 'stopped';
    updateTransportBtns();
    arrUpdateUI(0);
    cancelAnimationFrame(rafId);
}

function stopAllSources() {
    sources.forEach(s => { try { s.stop(); } catch(e) {} });
    sources = [];
}

function arrTick() {
    if (playState !== 'playing') return;
    const current = audioCtx.currentTime - startedAt;
    if (current >= arrMaxTime) { arrStop(); return; }
    arrUpdateUI(current);
    rafId = requestAnimationFrame(arrTick);
}

function arrUpdateUI(t) {
    const pct = Math.min(t / arrMaxTime * 100, 100);
    const ph  = document.getElementById('arr-playhead');
    if (ph) ph.style.left = pct + '%';

    const timeEl = document.getElementById('arr-time');
    if (timeEl) {
        const m = Math.floor(t / 60);
        const s = (t % 60).toFixed(2).padStart(5, '0');
        timeEl.textContent = `${String(m).padStart(2,'0')}:${s}`;
    }

    // Highlight active clips
    document.querySelectorAll('.arr-clip').forEach(el => {
        const s = parseFloat(el.dataset.start);
        const e = s + parseFloat(el.dataset.dur);
        el.classList.toggle('arr-clip-active', t >= s && t < e);
    });
}

function updateTransportBtns() {
    const play  = document.getElementById('arr-play');
    const pause = document.getElementById('arr-pause');
    const stop  = document.getElementById('arr-stop');
    if (play)  play.disabled  = playState === 'playing';
    if (pause) pause.disabled = playState !== 'playing';
    if (stop)  stop.disabled  = playState === 'stopped';
}

// ── Mute ──────────────────────────────────────────────────────
function arrToggleMute(trackId, btn) {
    if (mutedTracks.has(trackId)) {
        mutedTracks.delete(trackId);
        btn.classList.remove('muted');
        btn.title = 'Mute track';
    } else {
        mutedTracks.add(trackId);
        btn.classList.add('muted');
        btn.title = 'Unmute track';
    }
    // Mute/unmute live gain nodes for this track
    gainNodes.forEach((gain, clipId) => {
        const track = arrData.find(t => t.clips.some(c => c.clip_id === clipId));
        if (track?.track_id === trackId) {
            gain.gain.value = mutedTracks.has(trackId) ? 0 : 1;
        }
    });
    // Grey out the row
    const row = document.getElementById(`arr-row-${trackId}`);
    if (row) row.classList.toggle('arr-row-muted', mutedTracks.has(trackId));
}

// ── Drag & Drop ───────────────────────────────────────────────
function arrOnDragStart(e) {
    const el   = e.currentTarget;
    dragClipId = parseInt(el.dataset.clip);

    // Calculate click offset within the clip as a fraction of total timeline
    const rect     = el.getBoundingClientRect();
    const gridRect = document.getElementById('arr-grid').getBoundingClientRect();
    const clickX   = e.clientX - rect.left;   // px within clip
    const clipW    = rect.width;
    const clipFrac = clickX / clipW;           // 0–1 within clip
    const clipDur  = parseFloat(el.dataset.dur);
    const clipOffsetSec = clipFrac * clipDur;
    dragOffsetPct = clipOffsetSec / arrMaxTime; // fraction of total timeline

    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragClipId);
    el.classList.add('arr-clip-dragging');
}

function arrOnDrop(e, trackId) {
    e.preventDefault();
    if (!dragClipId) return;

    const grid     = document.getElementById('arr-grid');
    const gridRect = grid.getBoundingClientRect();
    const pct      = (e.clientX - gridRect.left) / gridRect.width;
    let   newStart = Math.max(0, (pct - dragOffsetPct) * arrMaxTime);
    newStart       = Math.round(newStart * 100) / 100; // 2 decimal places

    // Snap to 0.25 s grid
    newStart = Math.round(newStart * 4) / 4;

    // Optimistically move the DOM element
    const clipEl = document.getElementById(`arr-clip-${dragClipId}`);
    if (clipEl) {
        clipEl.style.left = (newStart / arrMaxTime * 100) + '%';
        clipEl.dataset.start = newStart;
        clipEl.classList.remove('arr-clip-dragging');
    }

    // Update in-memory data
    arrData.forEach(t => t.clips.forEach(c => {
        if (c.clip_id === dragClipId) c.start_time = newStart;
    }));

    // Persist to server
    const fd = new FormData();
    fd.append('clip_id',    dragClipId);
    fd.append('start_time', newStart);
    fetch('api/update_clip.php', { method: 'POST', body: fd })
        .catch(() => console.warn('Failed to save clip position'));

    dragClipId = null;
}

// Clean up drag class if dropped outside
document.addEventListener('dragend', () => {
    document.querySelectorAll('.arr-clip-dragging')
            .forEach(el => el.classList.remove('arr-clip-dragging'));
    dragClipId = null;
});

// ── Delete clip (double-click) ────────────────────────────────
function arrDeleteClip(clipId, el) {
    if (!confirm(`Delete this clip?`)) return;
    const fd = new FormData();
    fd.append('clip_id', clipId);
    fetch('api/delete_clip.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                el.remove();
                // Remove from in-memory data
                arrData.forEach(t => {
                    t.clips = t.clips.filter(c => c.clip_id !== clipId);
                });
            }
        });
}

// ── Right-click to add sample ─────────────────────────────────
function bindGridRightClick() {
    document.querySelectorAll('.arr-row').forEach(row => {
        row.addEventListener('contextmenu', e => {
            e.preventDefault();
            const trackId  = parseInt(row.dataset.track);
            const gridRect = document.getElementById('arr-grid').getBoundingClientRect();
            const pct      = (e.clientX - gridRect.left) / gridRect.width;
            const startSec = Math.round(Math.max(0, pct * arrMaxTime) * 4) / 4;

            ctxTrackId  = trackId;
            ctxStartTime = startSec;

            document.getElementById('sample-start').value = startSec.toFixed(2);
            document.getElementById('sample-dur').value   = '4.00';
            document.getElementById('sampleFileDisplay').textContent = '📎 Click to select audio file';
            document.getElementById('sampleFile').value  = '';
            document.getElementById('addSampleModal').classList.add('active');
        });
    });
}

async function arrSubmitSample() {
    if (!ctxTrackId) return;

    const fd = new FormData(document.getElementById('addSampleForm'));
    fd.append('track_id', ctxTrackId);

    const btn = document.querySelector('#addSampleModal .btn-primary');
    btn.textContent = 'Uploading...';
    btn.disabled    = true;

    try {
        const res  = await fetch('api/add_clip.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            document.getElementById('addSampleModal').classList.remove('active');
            // Add clip to the DOM without full reload
            addClipToDOM(data);
            // Update in-memory
            const track = arrData.find(t => t.track_id === data.track_id);
            if (track) track.clips.push({
                clip_id:    data.clip_id,
                start_time: data.start_time,
                duration:   data.duration,
                file_path:  data.file_path,
            });
            // Reload its buffer if it has a file
            if (data.file_path && audioCtx) {
                fetch(data.file_path)
                    .then(r => r.arrayBuffer())
                    .then(ab => audioCtx.decodeAudioData(ab))
                    .then(buf => buffers.set(data.clip_id, buf))
                    .catch(() => {});
            }
        } else {
            alert('Error: ' + (data.error || 'Unknown error'));
        }
    } finally {
        btn.textContent = 'Add to Track →';
        btn.disabled    = false;
    }
}

function addClipToDOM(data) {
    const row = document.getElementById(`arr-row-${data.track_id}`);
    if (!row) return;

    // Remove "drop hint" if present
    const hint = row.querySelector('.arr-row-hint');
    if (hint) hint.remove();

    const track = arrData.find(t => t.track_id === data.track_id);
    const type  = track ? track.track_type.toLowerCase() : 'other';

    const l = (data.start_time / arrMaxTime * 100).toFixed(4);
    const w = Math.max((data.duration / arrMaxTime * 100), 0.3).toFixed(4);
    const label = data.file_path ? data.file_path.split('/').pop() : `Clip #${data.clip_id}`;

    const div = document.createElement('div');
    div.className   = `arr-clip arr-clip-${type}`;
    div.id          = `arr-clip-${data.clip_id}`;
    div.style.left  = l + '%';
    div.style.width = w + '%';
    div.draggable   = true;
    div.dataset.clip  = data.clip_id;
    div.dataset.start = data.start_time;
    div.dataset.dur   = data.duration;
    div.dataset.track = data.track_id;
    div.dataset.file  = data.file_path || '';
    div.title = `${label} | ${fmtTime(data.start_time)} – ${fmtTime(data.start_time + data.duration)}`;
    div.innerHTML = `
        <span class="arr-clip-label">${escHtml(label)}</span>
        <div class="arr-clip-wave">${Array.from({length:12},()=>
            `<div class="arr-wave-bar" style="height:${20+Math.random()*80}%"></div>`).join('')}
        </div>`;
    div.addEventListener('dragstart', arrOnDragStart);
    div.addEventListener('dblclick', () => arrDeleteClip(data.clip_id, div));
    row.appendChild(div);

    // Re-bind right-click for new row state
    row.addEventListener('contextmenu', e => {
        e.preventDefault();
        const gridRect = document.getElementById('arr-grid').getBoundingClientRect();
        const pct      = (e.clientX - gridRect.left) / gridRect.width;
        ctxTrackId   = data.track_id;
        ctxStartTime = Math.round(Math.max(0, pct * arrMaxTime) * 4) / 4;
        document.getElementById('sample-start').value = ctxStartTime.toFixed(2);
        document.getElementById('sample-dur').value   = '4.00';
        document.getElementById('addSampleModal').classList.add('active');
    });
}

// ── Sync sidebar & grid vertical scroll ──────────────────────
function syncScroll() {
    const headers = document.getElementById('arr-track-headers');
    const grid    = document.getElementById('arr-grid-wrap');
    if (!headers || !grid) return;
    grid.addEventListener('scroll', () => { headers.scrollTop = grid.scrollTop; });
}

// ── Helpers ───────────────────────────────────────────────────
function fmtTime(sec) {
    const m = Math.floor(sec / 60);
    const s = (sec % 60).toFixed(2).padStart(5, '0');
    return `${String(m).padStart(2,'0')}:${s}`;
}
function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
