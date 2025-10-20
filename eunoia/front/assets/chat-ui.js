const chat = document.getElementById('chat');
const box  = document.getElementById('box');
const btn  = document.getElementById('send');
const pendingEl = document.getElementById('pending');
const pendingTextEl = document.getElementById('pendingText');
const MAX_CHAIN = 3;
const KB_EPS = 24;

const IS_LIKELY_IOS = /iP(hone|ad|od)/i.test(navigator.userAgent) ||
                      (/Mac/i.test(navigator.userAgent) && 'ontouchend' in document);
const IS_TOUCH = ('ontouchstart' in window) || navigator.maxTouchPoints > 0;

// in-page history only
const history = [];
let inFlight = false;

// user msg buffering
let debounceMs = 1500;
let pendingTimer = null;  
let pendingTick = null;   
let pendingETA = 0; 
let queuedCount = 0;
let lastTurnQueuedCount = 0;
let isComposing = false;
let isKeyboardOpen = false;
let _kbPrev = false;

let typingTimer = null;
let typingIdleMs = 6000;
let typingInitMs = 1800;
let typingETA = 0;

let lineInterval = 2001;

pendingEl.classList.add('on'); 
updatePendingText();
setInterval(updatePendingText, 500);

function rearmTypingGrace(){
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  typingETA = Date.now() + typingIdleMs;
  typingTimer = setTimeout(() => {
    typingTimer = null; typingETA = 0;
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }, typingIdleMs);
  updatePendingText();
}

function imeOpen(){
  if (isComposing) return true;
  const focused = document.activeElement === box;
  if (!focused) return false;

  const vv = window.visualViewport;
  if (vv){
    const occluded = window.innerHeight - (vv.height + vv.offsetTop);
    if (occluded > KB_EPS) return true;
  }

  return IS_LIKELY_IOS && focused;
}

(function(){
  const root = document.documentElement;
  const vv = window.visualViewport;

  let resizeTimeout;
  function debouncedSetAppHeight() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(setAppHeight, 10);
  }

  function setAppHeight(){
    const wasAtBottom = Math.abs(chat.scrollHeight - chat.scrollTop - chat.clientHeight) < 2;
    const prevScrollTop = chat.scrollTop;
    
    const vh   = vv ? vv.height : window.innerHeight;
    const vTop = vv ? vv.offsetTop : 0;
    root.style.setProperty('--app-h', vh + 'px');
    root.style.setProperty('--vv-top', vTop + 'px');

    const kbOpen = imeOpen();
    const wasKbOpen = root.classList.contains('ime-open');
    root.classList.toggle('ime-open', kbOpen);
    
    if (kbOpen !== wasKbOpen) {
      handleKeyboardToggle(kbOpen);
      
      if (kbOpen && !wasKbOpen) {
        requestAnimationFrame(() => {
          chat.scrollTop = prevScrollTop;
        });
      }
    }

    if (!kbOpen && wasAtBottom) {
      chat.scrollTop = chat.scrollHeight;
    }
  }

  setAppHeight();
  vv?.addEventListener('resize', setAppHeight);
  vv?.addEventListener?.('geometrychange', setAppHeight);
  window.addEventListener('orientationchange', setAppHeight);
})();

function debugOn(){ return document.documentElement.getAttribute('data-debug') !== 'off'; }
function sys(msg){ if (debugOn()) addBubble('sys', msg); }

let isInitialLoad = true;
function addBubble(role, text){
  if (role === 'sys') {
    const row = document.createElement('div');
    row.className = 'msg-row';
    const div = document.createElement('div');
    div.className = 'msg sys';
    div.textContent = text;
    row.appendChild(document.createElement('span'));
    row.appendChild(div);
    row.appendChild(document.createElement('span'));
    if (!isInitialLoad) { row.classList.add('new-message'); } // <-- Add this line
    chat.appendChild(row);
  } else {
    const row = document.createElement('div');
    row.className = 'msg-row';
    
    const avatar = document.createElement('img');
    avatar.className = 'avatar ' + (role === 'user' ? 'user-avatar' : 'assistant-avatar');
    avatar.src = role === 'user' ? '/usr/uploads/pic/IMG_1760.JPEG' : '/usr/uploads/pic/euno.jpg';
    
    const div = document.createElement('div');
    div.className = 'msg ' + (role === 'user' ? 'user' : 'assistant');
    if (role === 'assistant') { text = renderVisible(text); }
    div.textContent = text;
    
    if (role === 'user') {
      row.appendChild(document.createElement('span'));
      row.appendChild(div);
      row.appendChild(avatar);
    } else {
      row.appendChild(avatar);
      row.appendChild(div);
      row.appendChild(document.createElement('span'));
    }
    
    if (!isInitialLoad) { row.classList.add('new-message'); }
    chat.appendChild(row);
  }
  requestAnimationFrame(() => {
    chat.scrollTop = chat.scrollHeight;
  });
}

// display history chats
try {
  const histElem = document.getElementById('hist-data');
  if (histElem) {
    const hist = JSON.parse(histElem.textContent || '[]');
    hist.forEach(m => {
      const role = m.role || 'sys';
      const content = m.content || '';
      if (content) {
        parts = content.split(/\n\n+/);
        for (let i = 0; i < parts.length; i++) {
          addBubble(role, parts[i]); 
        }
      }
    });
  }
} catch (e) {
  console.error('Failed parsing history', e);
} finally { 
  isInitialLoad = false;
}

sys('New session started')

function showPending(ms){
  pendingETA = Date.now() + ms;
  pendingEl.classList.add('on');
  updatePendingText();
}

function clearPending(){
  pendingETA = 0;
  if (pendingTick) { clearInterval(pendingTick); pendingTick = null; }
  pendingTextEl.textContent = 'Idle';
  updatePendingText();
}

function esc(s){return String(s).replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]))}

function setVars(obj){
  const el = document.getElementById('pendingVars');
  if (document.documentElement.getAttribute('data-debug') === 'off'){ el.innerHTML=''; return; }
  el.innerHTML = Object.entries(obj).map(([k,v]) =>
    `<div class="kv"><span class="k">${esc(k)}</span><span class="v">${esc(v)}</span></div>`
  ).join('');
}

function updatePendingText(){
  const hasTimer = !!pendingTimer;
  const hasTyping = !!typingTimer;

  if (inFlight) {
    pendingTextEl.textContent = 'Sending…';
  } else if (hasTimer) {
    const left = Math.max(0, pendingETA - Date.now());
    pendingTextEl.textContent = `Pending send in ${(left/1000).toFixed(1)}s`;
  } else if (hasTyping) {
    const tleft = Math.max(0, typingETA - Date.now());
    const mode = (box === document.activeElement
                    ? (box.value.trim() ? 'typing grace' : 'focus grace')
                    : 'typing grace');
    pendingTextEl.textContent = `${mode} ${(tleft/1000).toFixed(1)}s`;
  } else if (isComposing) {
    pendingTextEl.textContent = 'IME composing';
  } else if (imeOpen()) {
    pendingTextEl.textContent = 'Idle (keyboard open)';
  } else {
    const suffix = (tailRole() === 'assistant') ? ' (awaiting user)' : '';
    pendingTextEl.textContent = 'Idle' + suffix;
  }

  const varsEl = document.getElementById('pendingVars');
  if (document.documentElement.getAttribute('data-debug') !== 'off') {
      setVars({
        pendingTimer: !!pendingTimer,
        lastTurnQueuedCount,
        inFlight,
        queuedCount: queuedCount - lastTurnQueuedCount,
        tailRole: tailRole(),
        typingTimer: !!typingTimer,
        isComposing,
        isKeyboardOpen,
      });
  } else {
    varsEl.textContent = '';
  }
}

/* ---------- fences: parse, render, route, interest ---------- */
function parseFencedBlocks(s){
  const re=/~~~(\w+)\s*(\{[\s\S]*?\})\s*~~~/giu, out=[]; let m;
  while((m=re.exec(s))){
    try{ out.push({tag:(m[1]||'').toLowerCase(), json:JSON.parse(m[2])}); }catch{}
  }
  return out;
}

function stripFences(s) {
  s = String(s || '').replace(/~~~\w+\s*\{[\s\S]*?\}\s*~~~/g, '').trim();
  // s = s.replace(/\n{2,}/g, '\n');
  return s;
}

function renderVisible(s){
  s = s.replace(/(?<=\s)(?:—|–|--|-)(?=\s)/g, ', ');
  return String(s||'')
    .replace(/~~~action[\s\S]*?~~~\s*/g,'[ACT]')
    .replace(/~~~memory[\s\S]*?~~~\s*/g,'[MEM]')
    .replace(/~~~interest[\s\S]*?~~~\s*/g,'[INT]')
    .trim();
}

async function routeBlocks(aiOutput, toolCalls){
  const hasFences = /~~~\w+[\s\S]*?~~~/m.test(aiOutput);
  const hasTools  = Array.isArray(toolCalls) && toolCalls.length>0;
  if(!hasFences && !hasTools) return null;

  const r = await fetch('action_router.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({
      ai_output: String(aiOutput||''),
      tool_calls: hasTools ? toolCalls : undefined
    }),
    credentials:'same-origin'
  });
  if(!r.ok) throw new Error('router http '+r.status);
  return await r.json();
}

function extractInterest(aiOutput){
  const blocks=parseFencedBlocks(aiOutput);
  let j=(blocks.find(b=>b.tag==='interest')||{}).json||null;
  if(!j){
    for(const b of blocks){ const o=b.json||{};
      if(Array.isArray(o.topics)&&typeof o.confidence==='number'){ j=o; break; }
    }
  }
  if(!j) return null;
  const confidence=Math.max(0,Math.min(1,Number(j.confidence)));
  if(!isFinite(confidence)) return null;
  return {
    topics:Array.isArray(j.topics)?j.topics.slice(0,4):[],
    reason:typeof j.reason==='string'?j.reason:'',
    confidence
  };
}

function traceIssuedFences(aiOutput){
  const blocks = parseFencedBlocks(aiOutput);
  for(const b of blocks){
    if (b.tag==='action')  sys('Action issued');
    else if (b.tag==='memory') sys('Memory update issued');
    else sys('Block '+b.tag+' issued');
  }
}

function traceRouterResult(router){
  if (!router) return;
  const handled = Array.isArray(router.handled) ? router.handled : [];
  const unhandled = Array.isArray(router.unhandled) ? router.unhandled : [];
  handled.forEach(h=>{
    if (!h) return;
    if (h.tag==='action'){
      const intent = h.intent || 'action';
      const note = h.note || h.status || (h.result ? 'ok' : 'not_found');
      addBubble('sys', 'Action '+intent+': '+note);
    } else if (h.tag==='memory'){
      sys('Memory '+(h.status||'ok')+(h.fact?': '+h.fact:''));
    } else {
      sys('Handled '+(h.tag||'block'));
    }
  });
  unhandled.forEach(u=>{
    if (!u) return;
    addBubble('sys', 'Unhandled '+(u.tag||'block')+(u.error?': '+u.error:'')); 
  });
}

function scheduleTurn() {
  if (inFlight) return;
  if (tailRole() === 'assistant'){
    clearPending();
    pendingTextEl.textContent = 'Idle (awaiting user)';
    updatePendingText();
    return;
  }
  if (imeOpen()) {
    const hasText = box.value.trim().length > 0;
    if (!hasText) {
      typingTimer = setTimeout( performModelTurn, typingInitMs);
      typingETA = Date.now() + typingInitMs;
    }
    return;
  }
  if (pendingTimer) clearTimeout(pendingTimer);
  pendingTimer = setTimeout(performModelTurn, debounceMs);
  showPending(debounceMs);
}

async function sendMessage(){
  const q = box.value.trim();
  if (!q) return;
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  addBubble('user', q);
  box.value = '';
  history.push({ role: 'user', content: q });
  queuedCount++;

  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  scheduleTurn();
}

async function performModelTurn() {
  if (tailRole() === 'assistant'){
    clearPending();
    pendingTextEl.textContent = 'Idle (awaiting user)';
    updatePendingText();
    return;
  }

  if (inFlight) return;
  inFlight = true;
  btn.disabled = true;
  clearPending();
  const turnStartQueued = queuedCount;
  if (lastTurnQueuedCount === queuedCount) {
    sys('Tried to submit API req with no new msg.');
    inFlight = false;
    btn.disabled = false;
    return;
  }
  lastTurnQueuedCount = turnStartQueued;
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; }

  try{
    const r = await fetch('', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ messages: history })
    });
    const j = await r.json();
    if (j.error){ addBubble('assistant', 'Error: ' + j.error); return; }

    const ans = String(j.answer ?? '');
    const toolCalls = Array.isArray(j.assistant_tool_calls) ? j.assistant_tool_calls : [];
    await handleAiOutput(ans, toolCalls, 0);

  } finally {
    inFlight = false;
    btn.disabled = false;

    if (queuedCount > lastTurnQueuedCount) {
      scheduleTurn();
    }
  }
}

async function handleAiOutput(aiOutput, toolCalls = [], depth = 0){
  if (!aiOutput && !(Array.isArray(toolCalls) && toolCalls.length)) return;
  const raw = String(aiOutput);
  const visible = stripFences(raw) || '*command*'; // visible should be non-empty.


  //addBubble('assistant', visible);
  const parts = visible.split(/\n\n+/);
  for (let i = 0; i < parts.length; i++) {
    setTimeout(() => addBubble('assistant', parts[i]), i * lineInterval); 
  }
  
  if (Array.isArray(toolCalls) && toolCalls.length){
    history.push({ role:'assistant', content: raw, tool_calls: toolCalls });
  } else {
    history.push({ role:'assistant', content: raw });
  }

  traceIssuedFences(raw);
  applyDebugOps(parseFencedBlocks(raw));
  applyDebugOpsFrom(toolCalls, null);

  let router = null;
  try {
    router = await routeBlocks(raw, toolCalls);
    traceRouterResult(router);
    applyDebugOpsFrom(toolCalls, router);
    // push ready tool messages returned by router (API-level calls)
    if (router) {
      if (Array.isArray(router.tool_messages) && router.tool_messages.length > 0) {
        for (const tm of router.tool_messages) {
          if (!tm || typeof tm !== 'object') continue;
          history.push({
            role: 'tool',
            tool_call_id: String(tm.tool_call_id || ''),
            content: String(tm.content || '')
          });
        }
      } else {
        //history.push({ role: 'system', content: 'function result: ' + JSON.stringify(router) })
        //history.push({ role: 'user', content: 'Continue your response with the last function result' });
        history.push({ role: 'user', content: 'function result: ' + JSON.stringify(router) });
      }
    }
  } catch (error) {
    console.error('Router error:', error);
    addBubble('sys', `Router error: ${error.message}`);
  }

  if (depth >= MAX_CHAIN) return;

  const handled = (router && Array.isArray(router.handled)) ? router.handled : [];
  const hasNonMemoryAction = handled.some(h => h && h.tag === 'action');

  if (hasNonMemoryAction){
    const r2 = await fetch('', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({ 
        messages: history, 
        last_func_call: Array.isArray(toolCalls) ? toolCalls : []
      })
    });
    const j2 = await r2.json();
    if (!j2.error){
      const toolCalls2 = Array.isArray(j2.assistant_tool_calls) ? j2.assistant_tool_calls : [];
      await handleAiOutput(String(j2.answer ?? ''), toolCalls2, depth + 1);
    } else {
      addBubble('sys', 'followup_error: ' + j2.error);
    }
    return;
  }

  const interest = resolveInterest(raw, toolCalls, router);
  if (interest){
    const p = Math.max(0, Math.min(1, interest.confidence + 0.2));
    if (Math.random() < p){
      sys('Interest trigger p=' + p.toFixed(2) + (interest.topics.length ? (' [' + interest.topics.join(', ') + ']') : ''));
      const r3 = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ 
          messages: history,
          last_func_call: Array.isArray(toolCalls) ? toolCalls : []
        })
      });
      const j3 = await r3.json();
      if (!j3.error){
        const toolCalls3 = Array.isArray(j3.assistant_tool_calls) ? j3.assistant_tool_calls : [];
        await handleAiOutput(String(j3.answer ?? ''), toolCalls3, depth + 1);
      } else {
        addBubble('sys', 'interest_followup_error: ' + j3.error);
      }
    }
  }
}

function safeParseJSON(s){ try{ return JSON.parse(String(s||'')); }catch{ return null; } }

function getToolFnArgs(toolCalls, fnName){
  if (!Array.isArray(toolCalls)) return null;
  for (const tc of toolCalls){
    if (!tc || tc.type !== 'function') continue;
    const f = tc.function || {};
    if ((f.name||'').toLowerCase() === fnName) return safeParseJSON(f.arguments);
  }
  return null;
}

function getRouterHandled(router, tag){
  if (!router || !Array.isArray(router.handled)) return null;
  for (const h of router.handled){ if (h && (h.tag||'').toLowerCase() === tag) return h; }
  return null;
}


function applyDebugOps(blocks){
  blocks.forEach(b=>{
    if (b.tag === 'debug' && b.json && typeof b.json.op === 'string'){
      const op = b.json.op.toLowerCase();
      if (op === 'on'){
        document.documentElement.setAttribute('data-debug','on');
        addBubble('sys','Debug mode ON');
      } else if (op === 'off'){
        document.documentElement.setAttribute('data-debug','off');
        addBubble('sys','Debug mode OFF');
      }
    }
  });
}

function applyDebugOpsFrom(toolCalls, router){
  // prefer explicit tool call
  const args = getToolFnArgs(toolCalls, 'debug');
  let op = (args && typeof args.op === 'string') ? args.op.toLowerCase() : null;

  // fallback to router-handled debug
  if (!op){
    const h = getRouterHandled(router, 'debug');
    if (h && h.payload && typeof h.payload === 'string') op = h.payload.toLowerCase();
  }
  if (!op) return;

  if (op === 'on'){
    document.documentElement.setAttribute('data-debug','on');
    addBubble('sys','Debug mode ON');
  } else if (op === 'off'){
    document.documentElement.setAttribute('data-debug','off');
    addBubble('sys','Debug mode OFF');
  }
}

function extractInterestFrom(toolCalls, router){
  // prefer explicit tool call
  let o = getToolFnArgs(toolCalls, 'interest');

  // fallback to router-handled interest
  if (!o){
    const h = getRouterHandled(router, 'interest');
    if (h && h.payload && typeof h.payload === 'object') o = h.payload;
  }
  if (!o) return null;

  const conf = Number(o.confidence);
  if (!isFinite(conf)) return null;
  return {
    topics: Array.isArray(o.topics) ? o.topics.slice(0,4) : [],
    reason: typeof o.reason === 'string' ? o.reason : '',
    confidence: Math.max(0, Math.min(1, conf))
  };
}

function resolveInterest(aiOutput, toolCalls, router){
  const a = extractInterestFrom(toolCalls, router);
  if (a && (a.topics.length || a.reason)) return a;
  const b = extractInterest(aiOutput);
  return (b && (b.topics.length || b.reason)) ? b : null;
}

function tailRole(){ 
  return history.length ? history[history.length-1].role : ''; 
}

function handleKeyboardToggle(open){
  if (open === _kbPrev) return;
  _kbPrev = open;
  isKeyboardOpen = open;

  if (open){
    if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
    if (typingTimer)  { clearTimeout(typingTimer);  typingTimer  = null; typingETA = 0; }
    clearPending();
    pendingTextEl.textContent = 'Idle (keyboard open)';
    updatePendingText();
  } else {
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }
}

btn.addEventListener('click', sendMessage);
box.addEventListener('keydown', (e)=>{
  if (e.keyCode === 229) {
    rearmTypingGrace();
    return;
  };
  if (e.key === 'Enter' && !e.shiftKey) {  e.preventDefault(); sendMessage(); }
});
box.addEventListener('focus', () => {        
  if (imeOpen()){                            
    handleKeyboardToggle(true);            
    return;                                  
  }
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }
  typingETA = Date.now() + typingIdleMs;
  typingTimer = setTimeout(() => {
    typingTimer = null; typingETA = 0;
    if (!inFlight && tailRole() === 'user') scheduleTurn();
    else updatePendingText();
  }, typingIdleMs);
  updatePendingText();
});
box.addEventListener('input', (e) => {
  const hasText = box.value.trim().length > 0;
  if (e.isComposing || isComposing || hasText) {
    rearmTypingGrace();
    return;
  };
  if (pendingTimer) { clearTimeout(pendingTimer); pendingTimer = null; }
  clearPending();

  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; }

  if (hasText) {
    typingETA = Date.now() + typingIdleMs;
    typingTimer = setTimeout(() => {
      typingTimer = null; typingETA = 0;
      if (!inFlight && tailRole() === 'user') {
        scheduleTurn();
      } else {
        updatePendingText();
      }
    }, typingIdleMs);
  } else {
    if (!inFlight && tailRole() === 'user') scheduleTurn();
  }
});

box.addEventListener('blur', () => {
  if (typingTimer) { clearTimeout(typingTimer); typingTimer = null; typingETA = 0; updatePendingText(); }
  if (!inFlight && !pendingTimer && queuedCount > lastTurnQueuedCount) scheduleTurn();
});
box.addEventListener('compositionstart', () => {
  isComposing = true;
  handleKeyboardToggle(true);
});
box.addEventListener('compositionend', () => {
  isComposing = false;
});

box.addEventListener('touchstart', () => {
  if (!IS_TOUCH) return;               
  rearmTypingGrace();
}, {passive:true});

box.addEventListener('compositionupdate', () => {
  rearmTypingGrace();
});

