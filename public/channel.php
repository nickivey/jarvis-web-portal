<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../jwt.php';
require_once __DIR__ . '/../helpers.php';
[$uid, $u] = require_jwt_user();
$defaultChannel = 'local:rhats';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Channels — JARVIS</title>
  <link rel="stylesheet" href="/style.css">
  <style>
    .channel-wrap{display:flex;gap:18px;align-items:flex-start}
    .channel-panel{flex:1}
    .channel-input{display:flex;gap:8px;margin-bottom:12px}
    .channel-input input{flex:1}
    .message-row{display:flex;gap:12px;padding:10px;border-radius:10px;border:1px solid rgba(255,255,255,.02);background:linear-gradient(180deg, rgba(255,255,255,.01), rgba(255,255,255,.00));margin-bottom:8px}
    .message-meta{font-size:13px;color:var(--muted)}
    .hashtag{color:var(--blue2);cursor:pointer;margin-left:6px}
  </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<div class="container">
  <h1>Channels</h1>
  <p class="muted">Interact with JARVIS in local channels. Use <code>#hashtags</code> to tag topics and filter messages.</p>
  <div style="margin-top:12px;margin-bottom:12px">
    <label>Channel</label>
    <input id="channelName" type="text" value="<?= htmlspecialchars($defaultChannel) ?>" />
  </div>
  <div class="channel-wrap">
    <div class="channel-panel">
      <div class="channel-input">
        <input id="msgInput" placeholder="Write a message and include #hashtags to tag topics" />
        <button id="sendBtn" class="btn">Send</button>
      </div>
      <div id="messagesList"></div>
    </div>
    <div style="width:320px">
      <div class="card">
        <h3>About Channels</h3>
        <p class="muted">Channels are local message spaces (namespace them with <code>local:*</code>) where you can post updates, link projects, and tag topics using <code>#hashtags</code>. Messages are private to your account by default.</p>
        <h4 style="margin-top:12px">Example Channel</h4>
        <div class="muted">Use <code>local:rhats</code> to mirror a small team channel for project discussions and notes.</div>
      </div>
    </div>
  </div>
</div>
<script>
async function loadMessages(channel, tag){
  const list = document.getElementById('messagesList'); list.innerHTML='';
  try{
    const q = new URLSearchParams(); q.set('channel', channel); if (tag) q.set('tag', tag);
    const r = await fetch('/api/messages?' + q.toString(), { headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } });
    const j = await r.json();
    if (!j || !Array.isArray(j.messages)) return;
    j.messages.forEach(m => {
      const d = document.createElement('div'); d.className='message-row';
      const left = document.createElement('div'); left.style.width='70px'; left.style.fontSize='13px'; left.style.color='var(--muted)'; left.innerText = (m.username || 'you');
      const right = document.createElement('div'); const txt = document.createElement('div'); txt.innerHTML = m.message_text.replace(/(#([A-Za-z0-9_\-]+))/g, '<span class="hashtag" data-tag="$2">$1</span>').replace(/@([A-Za-z0-9_\-]+)/g, '<span style="color:var(--ok)">$&</span>');
      const meta = document.createElement('div'); meta.className='message-meta'; meta.innerText = new Date(m.created_at).toLocaleString();
      right.appendChild(txt); right.appendChild(meta);

      // actions (delete)
      const actions = document.createElement('div'); actions.style.marginTop='6px';
      if ((m.user_id && m.user_id === <?= (int)$uid ?>) || '<?= ($u['role'] ?? '') ?>' === 'admin') {
        const dbtn = document.createElement('button'); dbtn.className='btn secondary deleteMsgBtn'; dbtn.dataset.id = m.id; dbtn.textContent = 'Delete';
        dbtn.addEventListener('click', async ()=>{
          if (!confirm('Delete this message?')) return;
          const r = await fetch('/api/messages/' + m.id, { method: 'DELETE', headers: { 'Authorization': 'Bearer ' + (window.jarvisJwt || '') } });
          const j = await r.json(); if (j && j.ok) loadMessages(document.getElementById('channelName').value);
        });
        actions.appendChild(dbtn);
      }

      right.appendChild(actions);
      d.appendChild(left); d.appendChild(right);
      list.appendChild(d);
    });
    // wire hashtag clicks
    document.querySelectorAll('.hashtag').forEach(el => el.addEventListener('click', (e)=>{ const t = e.target.getAttribute('data-tag'); document.getElementById('channelName').scrollIntoView(); loadMessages(channel, t); }));
  }catch(e){ console.error(e); }
}

document.getElementById('sendBtn').addEventListener('click', async ()=>{
  const ch = document.getElementById('channelName').value.trim(); const msg = document.getElementById('msgInput').value.trim();
  if (!ch || !msg) return alert('channel and message required');
  try{
    const r = await fetch('/api/messages', { method: 'POST', headers: { 'Content-Type':'application/json', 'Authorization': 'Bearer ' + (window.jarvisJwt || '') }, body: JSON.stringify({ channel: ch, message: msg, provider: 'local' }) });
    const j = await r.json();
    if (j && j.ok) { document.getElementById('msgInput').value=''; loadMessages(ch); }
  }catch(e){ console.error(e); }
});

// load default channel
loadMessages(document.getElementById('channelName').value);
</script>
</body></html>