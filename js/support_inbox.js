// Support inbox for Admin/Processor

(function () {
  const ctx = window.NFASupportInbox || {};
  const qs = (sel, root = document) => root.querySelector(sel);

  function apiUrl(action, params = {}) {
    const url = new URL('php_helper/api.php', window.location.href);
    url.searchParams.set('action', action);
    Object.entries(params).forEach(([k, v]) => {
      if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
    });
    return url.toString();
  }

  async function getJson(action, params = {}) {
    const res = await fetch(apiUrl(action, params), { cache: 'no-store', headers: { 'Accept': 'application/json' } });
    const json = await res.json().catch(() => null);
    if (!res.ok) throw new Error(json?.error || 'Request failed');
    return json;
  }

  async function postJson(action, payload = {}) {
    const form = new URLSearchParams();
    Object.entries(payload || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null) form.set(k, String(v));
    });
    const res = await fetch(apiUrl(action), {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json' },
      body: form.toString()
    });
    const json = await res.json().catch(() => null);
    if (!res.ok) throw new Error(json?.error || 'Request failed');
    return json;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatTime(ts) {
    try {
      const d = new Date(ts);
      if (isNaN(d.getTime())) return '';
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  }

  const listEl = qs('#supportChatList');
  const messagesEl = qs('#supportMessages');
  const titleEl = qs('#supportActiveTitle');
  const subEl = qs('#supportActiveSub');
  const inputEl = qs('#supportMsgInput');
  const sendBtn = qs('#supportSendBtn');
  const closeBtn = qs('#btnCloseChat');

  let activeChatId = 0;
  let lastMsgId = 0;
  let pollTimer = null;

  function setEnabled(enabled) {
    inputEl.disabled = !enabled;
    sendBtn.disabled = !enabled;
    closeBtn.disabled = !enabled;
  }

  function renderList(chats) {
    if (!Array.isArray(chats) || chats.length === 0) {
      listEl.innerHTML = '<div style="padding: 12px 14px; color: rgba(0,0,0,0.65);">No active conversations.</div>';
      return;
    }

    listEl.innerHTML = chats.map(c => {
      const active = (parseInt(c.chat_id, 10) || 0) === activeChatId;
      const needsReply = !!c.needs_reply;
      const unread = needsReply && !active;
      return `
        <div class="support-item ${active ? 'active' : ''} ${unread ? 'unread' : ''}" data-chat-id="${c.chat_id}">
          <div class="support-title">${escapeHtml(c.title || 'Conversation')}${unread ? '<span class="support-unread-dot" title="Unread"></span>' : ''}</div>
          <div class="support-sub">${escapeHtml(c.subtitle || '')}</div>
        </div>
      `;
    }).join('');
  }

  function renderMessages(items, append = false) {
    if (!append) {
      messagesEl.innerHTML = '';
    }

    if (!items || items.length === 0) {
      if (!append) {
        messagesEl.innerHTML = '<div class="support-chat-empty" style="margin: 12px;">No messages yet.</div>';
      }
      return;
    }

    const empty = messagesEl.querySelector('.support-chat-empty');
    if (empty) empty.remove();

    items.forEach(m => {
      const bubble = document.createElement('div');
      bubble.className = 'support-bubble';

      const role = String(m.sender_role || '').toLowerCase();
      const isMe = (ctx.userType || '').toLowerCase() === role;
      if (isMe) bubble.classList.add('me');

      bubble.innerHTML = `
        <div>${escapeHtml(m.message || '')}</div>
        <div class="support-meta">${escapeHtml(m.sender_label || role || 'user')} • ${escapeHtml(formatTime(m.created_at))}</div>
      `;
      messagesEl.appendChild(bubble);
    });

    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  async function refreshChats() {
    const resp = await getJson('staffListSupportChats');
    if (!resp?.success) return;
    renderList(resp.chats || []);
  }

  async function refreshMessages() {
    if (!activeChatId) return;
    const resp = await getJson('staffGetSupportChatMessages', { chat_id: activeChatId, since_id: lastMsgId });
    if (!resp?.success) return;

    const list = Array.isArray(resp.messages) ? resp.messages : [];
    if (typeof resp.last_id === 'number') lastMsgId = resp.last_id;
    else if (list.length) lastMsgId = Math.max(lastMsgId, ...list.map(x => parseInt(x.id, 10) || 0));

    renderMessages(list, true);
  }

  function stopPoll() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startPoll() {
    stopPoll();
    pollTimer = setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      refreshMessages().catch(() => {});
      refreshChats().catch(() => {});
    }, 2500);
  }

  async function selectChat(chatId, title, subtitle) {
    activeChatId = chatId;
    lastMsgId = 0;
    titleEl.textContent = title || 'Conversation';
    subEl.textContent = subtitle || '';
    setEnabled(true);
    renderMessages([], false);

    const resp = await getJson('staffGetSupportChatMessages', { chat_id: activeChatId, since_id: 0 });
    if (resp?.success) {
      const list = Array.isArray(resp.messages) ? resp.messages : [];
      lastMsgId = typeof resp.last_id === 'number' ? resp.last_id : (list.length ? Math.max(...list.map(x => parseInt(x.id, 10) || 0)) : 0);
      renderMessages(list, false);
    }

    await refreshChats();
    startPoll();
  }

  async function sendMessage() {
    const text = (inputEl.value || '').trim();
    if (!text || !activeChatId) return;
    inputEl.value = '';
    await postJson('staffSendSupportChatMessage', { chat_id: activeChatId, message: text });
    await refreshMessages();
  }

  async function closeChat() {
    if (!activeChatId) return;
    if (!confirm('Close this conversation? (This ends the chat)')) return;
    await postJson('staffCloseSupportChat', { chat_id: activeChatId });
    activeChatId = 0;
    lastMsgId = 0;
    stopPoll();
    titleEl.textContent = 'Select a conversation';
    subEl.textContent = 'Messages appear here.';
    setEnabled(false);
    messagesEl.innerHTML = '<div class="support-chat-empty" style="margin: 12px;">No conversation selected.</div>';
    await refreshChats();
  }

  async function contactAdmin() {
    // Processor-only: create or reuse a processor->admin chat
    try {
      const resp = await postJson('staffStartSupportChat', { origin: 'processor' });
      if (!resp?.success || !resp.chat_id) throw new Error(resp?.error || 'Failed');
      await refreshChats();
      await selectChat(parseInt(resp.chat_id, 10) || 0, resp.title || 'Admin Support', resp.subtitle || '');
    } catch (e) {
      alert(e?.message || 'Failed to contact admin.');
    }
  }

  document.addEventListener('click', (e) => {
    const item = e.target.closest('.support-item');
    if (!item) return;
    const chatId = parseInt(item.getAttribute('data-chat-id') || '0', 10) || 0;
    if (!chatId) return;

    const title = item.querySelector('.support-title')?.textContent || 'Conversation';
    const subtitle = item.querySelector('.support-sub')?.textContent || '';
    selectChat(chatId, title, subtitle).catch(err => alert(err?.message || 'Failed to open chat.'));
  });

  qs('#btnRefreshChats')?.addEventListener('click', () => refreshChats().catch(() => {}));
  qs('#btnCloseChat')?.addEventListener('click', () => closeChat().catch(e => alert(e?.message || 'Failed to close chat.')));
  qs('#btnContactAdmin')?.addEventListener('click', () => contactAdmin());

  sendBtn.addEventListener('click', () => sendMessage().catch(e => alert(e?.message || 'Failed to send message.')));
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage().catch(e2 => alert(e2?.message || 'Failed to send message.'));
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    setEnabled(false);
    refreshChats().catch(() => {
      listEl.innerHTML = '<div style="padding: 12px 14px; color: rgba(0,0,0,0.65);">Failed to load chats.</div>';
    });
  });
})();
