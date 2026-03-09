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
let panNodes   = new Map();   // track_id → StereoPannerNode
let mutedTracks= new Set();
let soloTracks = new Set();
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

// Copy-paste state
let copiedClip    = null;
let selectedClipEl = null;

// Effects panel state
let fxTrackId = null;

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initAudio();
    bindTransport();
    bindGridRightClick();
    bindClipContextMenu();
    bindKeyboardShortcuts();
    bindDeselect();
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
    const hasSolo  = soloTracks.size > 0;

    allClips.forEach(clip => {
        const buf = buffers.get(clip.clip_id);
        if (!buf) return;

        const trackMuted = mutedTracks.has(clip.track_id);
        const trackSoloed = hasSolo && !soloTracks.has(clip.track_id);
        if (trackMuted || trackSoloed) return;

        const clipEnd = clip.start_time + clip.duration;
        if (clipEnd <= offset) return;

        const trackData = arrData.find(t => t.track_id === clip.track_id);
        const vol = trackData ? trackData.volume : 1.0;
        const panVal = trackData ? trackData.pan : 0.0;

        const gain = audioCtx.createGain();
        gain.gain.value = vol;

        const panner = audioCtx.createStereoPanner();
        panner.pan.value = panVal;

        gain.connect(panner);
        panner.connect(audioCtx.destination);
        gainNodes.set(clip.clip_id, gain);
        panNodes.set(clip.clip_id, panner);

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

// ── Right-click to add sample / paste ───────────────────────────
function bindGridRightClick() {
    document.querySelectorAll('.arr-row').forEach(row => {
        row.addEventListener('contextmenu', e => {
            e.preventDefault();
            const trackId  = parseInt(row.dataset.track);
            const gridRect = document.getElementById('arr-grid').getBoundingClientRect();
            const pct      = (e.clientX - gridRect.left) / gridRect.width;
            const startSec = Math.round(Math.max(0, pct * arrMaxTime) * 4) / 4;

            ctxTrackId   = trackId;
            ctxStartTime = startSec;

            const items = [
                { label: '🎵 Add Sample Here', action: () => {
                    document.getElementById('sample-start').value = startSec.toFixed(2);
                    document.getElementById('sample-dur').value   = '4.00';
                    document.getElementById('sampleFileDisplay').textContent = '📎 Click to select audio file';
                    document.getElementById('sampleFile').value  = '';
                    document.getElementById('addSampleModal').classList.add('active');
                }},
            ];
            if (copiedClip) {
                items.push({ label: '📋 Paste Clip Here', action: () => arrPasteClip(trackId, startSec) });
            }
            showCtxMenu(e.clientX, e.clientY, items);
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
    div.addEventListener('click', e => { e.stopPropagation(); arrSelectClip(div); });
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

// ── Copy / Paste ──────────────────────────────────────────────
function arrSelectClip(el) {
    if (selectedClipEl && selectedClipEl !== el) {
        selectedClipEl.classList.remove('arr-clip-selected');
    }
    if (selectedClipEl === el) {
        selectedClipEl.classList.remove('arr-clip-selected');
        selectedClipEl = null;
        return;
    }
    selectedClipEl = el;
    el.classList.add('arr-clip-selected');
}

function arrCopyClip(clipId) {
    let found = null, trackFound = null;
    arrData.forEach(t => {
        const c = t.clips.find(c => c.clip_id === clipId);
        if (c) { found = c; trackFound = t; }
    });
    if (!found) return;
    copiedClip = {
        clip_id:    found.clip_id,
        duration:   found.duration,
        file_path:  found.file_path,
        track_id:   trackFound.track_id,
        track_type: trackFound.track_type,
    };
    showToast('📋 Clip copied — Ctrl+V to paste, or right-click a row');
}

async function arrPasteClip(trackId, startTime) {
    if (!copiedClip) { showToast('Nothing to paste — copy a clip first'); return; }
    const fd = new FormData();
    fd.append('track_id',   trackId);
    fd.append('start_time', startTime);
    fd.append('duration',   copiedClip.duration);
    if (copiedClip.file_path) fd.append('existing_file_path', copiedClip.file_path);
    try {
        const res  = await fetch('api/add_clip.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            addClipToDOM(data);
            const track = arrData.find(t => t.track_id === data.track_id);
            if (track) track.clips.push({
                clip_id:    data.clip_id,
                start_time: data.start_time,
                duration:   data.duration,
                file_path:  data.file_path,
            });
            // Reuse decoded audio buffer if the file path is the same
            if (data.file_path && audioCtx) {
                const reuseBuf = (copiedClip.file_path === data.file_path)
                    ? buffers.get(copiedClip.clip_id) : null;
                if (reuseBuf) {
                    buffers.set(data.clip_id, reuseBuf);
                } else {
                    fetch(data.file_path)
                        .then(r => r.arrayBuffer())
                        .then(ab => audioCtx.decodeAudioData(ab))
                        .then(buf => buffers.set(data.clip_id, buf))
                        .catch(() => {});
                }
            }
            showToast('✅ Clip pasted');
        } else {
            alert('Paste failed: ' + (data.error || 'Unknown error'));
        }
    } catch(e) {
        console.warn('Paste error:', e);
    }
}

// ── Clip right-click context menu (capture phase) ─────────────
function bindClipContextMenu() {
    document.getElementById('arr-grid')?.addEventListener('contextmenu', e => {
        const clip = e.target.closest('.arr-clip');
        if (!clip) return;
        e.preventDefault();
        e.stopPropagation(); // prevent row's contextmenu from also firing
        arrSelectClip(clip);
        const clipId = parseInt(clip.dataset.clip);
        showCtxMenu(e.clientX, e.clientY, [
            { label: '📋 Copy Clip',   action: () => arrCopyClip(clipId) },
            'separator',
            { label: '🗑 Delete Clip', action: () => arrDeleteClip(clipId, clip) },
        ]);
    }, true); // capture phase — fires before row handler
}

// ── Keyboard shortcuts: Ctrl+C / Ctrl+V ───────────────────────
function bindKeyboardShortcuts() {
    document.addEventListener('keydown', e => {
        const tag = e.target.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (e.ctrlKey && e.key === 'c') {
            if (selectedClipEl) {
                e.preventDefault();
                arrCopyClip(parseInt(selectedClipEl.dataset.clip));
            }
        }
        if (e.ctrlKey && e.key === 'v') {
            if (copiedClip) {
                e.preventDefault();
                const startTime = Math.round(pausedAt * 4) / 4;
                arrPasteClip(copiedClip.track_id, startTime);
            }
        }
    });
}

// ── Click away to deselect + hide context menu ────────────────
function bindDeselect() {
    document.addEventListener('click', e => {
        if (!e.target.closest('.arr-ctx-menu')) hideCtxMenu();
        if (!e.target.closest('.arr-clip') && selectedClipEl) {
            selectedClipEl.classList.remove('arr-clip-selected');
            selectedClipEl = null;
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') hideCtxMenu();
    });
}

// ── Context menu UI ───────────────────────────────────────────
function showCtxMenu(x, y, items) {
    let menu = document.getElementById('arr-ctx-menu');
    if (!menu) {
        menu = document.createElement('div');
        menu.id = 'arr-ctx-menu';
        menu.className = 'arr-ctx-menu';
        document.body.appendChild(menu);
    }
    menu.innerHTML = '';
    items.forEach(item => {
        if (item === 'separator') {
            const sep = document.createElement('div');
            sep.className = 'arr-ctx-sep';
            menu.appendChild(sep);
        } else {
            const btn = document.createElement('button');
            btn.className   = 'arr-ctx-item';
            btn.textContent = item.label;
            btn.addEventListener('click', () => { hideCtxMenu(); item.action(); });
            menu.appendChild(btn);
        }
    });
    // Position and clamp to viewport
    menu.style.left = x + 'px';
    menu.style.top  = y + 'px';
    menu.style.visibility = 'hidden';
    menu.classList.add('visible');
    const rect = menu.getBoundingClientRect();
    if (x + rect.width  > window.innerWidth)  menu.style.left = (x - rect.width)  + 'px';
    if (y + rect.height > window.innerHeight) menu.style.top  = (y - rect.height) + 'px';
    menu.style.visibility = '';
}

function hideCtxMenu() {
    const menu = document.getElementById('arr-ctx-menu');
    if (menu) menu.classList.remove('visible');
}

// ── Toast Notification ────────────────────────────────────────
function showToast(msg) {
    const t = document.createElement('div');
    t.className   = 'arr-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(() => t.classList.add('arr-toast-visible'));
    setTimeout(() => {
        t.classList.remove('arr-toast-visible');
        setTimeout(() => t.remove(), 400);
    }, 2500);
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

// ── Mixer: Volume ─────────────────────────────────────────────
function arrSetVolume(trackId, value) {
    const vol = parseFloat(value);
    const track = arrData.find(t => t.track_id === trackId);
    if (track) track.volume = vol;

    // Update live gain nodes for clips on this track
    if (playState === 'playing') {
        arrData.forEach(t => {
            if (t.track_id !== trackId) return;
            t.clips.forEach(c => {
                const g = gainNodes.get(c.clip_id);
                if (g) g.gain.value = mutedTracks.has(trackId) ? 0 : vol;
            });
        });
    }

    // Persist (debounced via the browser's input event throttle)
    const fd = new FormData();
    fd.append('track_id', trackId);
    fd.append('volume', vol);
    fetch('api/track_settings.php', { method: 'POST', body: fd }).catch(() => {});
}

// ── Mixer: Pan ────────────────────────────────────────────────
function arrSetPan(trackId, value) {
    const panVal = parseFloat(value);
    const track = arrData.find(t => t.track_id === trackId);
    if (track) track.pan = panVal;

    // Update live panner nodes
    if (playState === 'playing') {
        arrData.forEach(t => {
            if (t.track_id !== trackId) return;
            t.clips.forEach(c => {
                const p = panNodes.get(c.clip_id);
                if (p) p.pan.value = panVal;
            });
        });
    }

    const fd = new FormData();
    fd.append('track_id', trackId);
    fd.append('pan', panVal);
    fetch('api/track_settings.php', { method: 'POST', body: fd }).catch(() => {});
}

// ── Solo Toggle ───────────────────────────────────────────────
function arrToggleSolo(trackId, btn) {
    if (soloTracks.has(trackId)) {
        soloTracks.delete(trackId);
        btn.classList.remove('soloed');
    } else {
        soloTracks.add(trackId);
        btn.classList.add('soloed');
    }

    // Persist solo state
    const fd = new FormData();
    fd.append('track_id', trackId);
    fd.append('is_solo', soloTracks.has(trackId) ? 1 : 0);
    fetch('api/track_settings.php', { method: 'POST', body: fd }).catch(() => {});

    // If currently playing, restart to apply solo routing
    if (playState === 'playing') {
        const currentTime = audioCtx.currentTime - startedAt;
        stopAllSources();
        pausedAt = currentTime;
        playState = 'paused';
        arrPlay();
    }
}

// ── Effects Panel ─────────────────────────────────────────────
async function openEffectsPanel(trackId) {
    fxTrackId = trackId;
    document.getElementById('effectsModal').classList.add('active');
    document.getElementById('fx-chain-list').innerHTML = 'Loading...';

    try {
        const res = await fetch(`api/track_effects.php?track_id=${trackId}`);
        const data = await res.json();
        if (!data.ok) { document.getElementById('fx-chain-list').innerHTML = 'Error loading effects'; return; }

        // Populate available effects dropdown
        const sel = document.getElementById('fx-select');
        sel.innerHTML = '';
        data.available.forEach(fx => {
            const opt = document.createElement('option');
            opt.value = fx.effect_id;
            opt.textContent = fx.effect_name;
            sel.appendChild(opt);
        });

        // Render current chain
        renderFxChain(data.chain);
    } catch(e) {
        document.getElementById('fx-chain-list').innerHTML = 'Failed to load effects.';
    }
}

function renderFxChain(chain) {
    const list = document.getElementById('fx-chain-list');
    if (!chain || chain.length === 0) {
        list.innerHTML = '<em>No effects on this track.</em>';
        return;
    }
    list.innerHTML = chain.map((fx, i) => `
        <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .6rem;background:var(--bg-card);border:1px solid var(--border);border-radius:6px;margin-bottom:.4rem;">
            <span style="color:var(--accent-light);font-weight:600;">${i+1}.</span>
            <span style="flex:1;">${escHtml(fx.effect_name)}</span>
            <span style="font-size:.75rem;color:var(--text-muted);">Mix: ${Math.round(fx.mix * 100)}%</span>
            <button class="btn btn-secondary btn-sm" style="padding:.15rem .5rem;font-size:.7rem;"
                    onclick="removeEffect(${fx.track_effect_id})">✕</button>
        </div>
    `).join('');
}

async function addEffect() {
    if (!fxTrackId) return;
    const effectId = document.getElementById('fx-select').value;
    if (!effectId) return;

    const fd = new FormData();
    fd.append('track_id', fxTrackId);
    fd.append('action', 'add');
    fd.append('effect_id', effectId);

    try {
        const res = await fetch('api/track_effects.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            openEffectsPanel(fxTrackId); // reload
        } else {
            alert('Error: ' + (data.error || 'Unknown'));
        }
    } catch(e) {
        console.warn('addEffect error:', e);
    }
}

async function removeEffect(trackEffectId) {
    const fd = new FormData();
    fd.append('track_id', fxTrackId);
    fd.append('action', 'remove');
    fd.append('track_effect_id', trackEffectId);

    try {
        const res = await fetch('api/track_effects.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.ok) {
            openEffectsPanel(fxTrackId); // reload
        }
    } catch(e) {
        console.warn('removeEffect error:', e);
    }
}

// ── Export Project (Offline Render) ───────────────────────────
async function arrExportProject() {
    const statusEl = document.getElementById('export-status');
    const btn      = document.getElementById('export-btn');
    btn.disabled = true;
    statusEl.textContent = 'Rendering...';

    try {
        const sampleRate = audioCtx.sampleRate;
        const length     = Math.ceil(arrMaxTime * sampleRate);
        const offCtx     = new OfflineAudioContext(2, length, sampleRate);

        const hasSolo = soloTracks.size > 0;
        const allClips = arrData.flatMap(t => t.clips.map(c => ({ ...c, track_id: t.track_id })));

        allClips.forEach(clip => {
            const buf = buffers.get(clip.clip_id);
            if (!buf) return;
            if (mutedTracks.has(clip.track_id)) return;
            if (hasSolo && !soloTracks.has(clip.track_id)) return;

            const trackData = arrData.find(t => t.track_id === clip.track_id);
            const vol    = trackData ? trackData.volume : 1.0;
            const panVal = trackData ? trackData.pan : 0.0;

            const gain   = offCtx.createGain();
            gain.gain.value = vol;
            const panner = offCtx.createStereoPanner();
            panner.pan.value = panVal;
            gain.connect(panner);
            panner.connect(offCtx.destination);

            const src = offCtx.createBufferSource();
            src.buffer = buf;
            src.connect(gain);
            src.start(clip.start_time, 0, clip.duration);
        });

        statusEl.textContent = 'Rendering audio...';
        const rendered = await offCtx.startRendering();

        statusEl.textContent = 'Encoding WAV...';
        const wav = encodeWAV(rendered);
        const blob = new Blob([wav], { type: 'audio/wav' });

        // Download
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'CatarataDAW_Export.wav';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);

        // Log export
        const fd = new FormData();
        fd.append('project_id', arrProjectId);
        fd.append('format', 'wav');
        fd.append('file_size', blob.size);
        fetch('api/export_log.php', { method: 'POST', body: fd }).catch(() => {});

        statusEl.textContent = '✅ Export complete! File downloaded.';
    } catch(e) {
        console.error('Export error:', e);
        statusEl.textContent = '❌ Export failed: ' + e.message;
    } finally {
        btn.disabled = false;
    }
}

function encodeWAV(audioBuffer) {
    const numCh  = audioBuffer.numberOfChannels;
    const length = audioBuffer.length;
    const sr     = audioBuffer.sampleRate;
    const bitsPerSample = 16;
    const bytesPerSample = bitsPerSample / 8;
    const blockAlign     = numCh * bytesPerSample;
    const byteRate       = sr * blockAlign;
    const dataSize       = length * blockAlign;

    const buffer = new ArrayBuffer(44 + dataSize);
    const view   = new DataView(buffer);

    // WAV header
    writeStr(view, 0, 'RIFF');
    view.setUint32(4, 36 + dataSize, true);
    writeStr(view, 8, 'WAVE');
    writeStr(view, 12, 'fmt ');
    view.setUint32(16, 16, true);
    view.setUint16(20, 1, true); // PCM
    view.setUint16(22, numCh, true);
    view.setUint32(24, sr, true);
    view.setUint32(28, byteRate, true);
    view.setUint16(32, blockAlign, true);
    view.setUint16(34, bitsPerSample, true);
    writeStr(view, 36, 'data');
    view.setUint32(40, dataSize, true);

    // Interleave channels
    const channels = [];
    for (let ch = 0; ch < numCh; ch++) channels.push(audioBuffer.getChannelData(ch));

    let offset = 44;
    for (let i = 0; i < length; i++) {
        for (let ch = 0; ch < numCh; ch++) {
            let sample = Math.max(-1, Math.min(1, channels[ch][i]));
            sample = sample < 0 ? sample * 0x8000 : sample * 0x7FFF;
            view.setInt16(offset, sample, true);
            offset += 2;
        }
    }
    return buffer;
}

function writeStr(view, offset, str) {
    for (let i = 0; i < str.length; i++) view.setUint8(offset + i, str.charCodeAt(i));
}

// ── Init muted/solo state from server data ────────────────────
(function initMixerState() {
    arrData.forEach(t => {
        if (t.is_muted) {
            mutedTracks.add(t.track_id);
            const btn = document.querySelector(`.arr-track-header[data-track="${t.track_id}"] .arr-mute-btn`);
            if (btn) btn.classList.add('muted');
            const row = document.getElementById(`arr-row-${t.track_id}`);
            if (row) row.classList.toggle('arr-row-muted', true);
        }
        if (t.is_solo) {
            soloTracks.add(t.track_id);
            const btn = document.querySelector(`.arr-track-header[data-track="${t.track_id}"] .arr-solo-btn`);
            if (btn) btn.classList.add('soloed');
        }
    });
})();
