// Simple support chat widget (farmer)
// - Farmer selects region + branch (no account required)
// - Polls messages while the page/tab stays open
// - Chat token stored in sessionStorage (ends when tab/browser closes)

(function () {
  const STORAGE_KEY = 'nfa_support_chat_token_v1';

  const qs = (sel, root = document) => root.querySelector(sel);
  const ce = (tag, cls) => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    return el;
  };

  function formatTime(ts) {
    try {
      const d = new Date(ts);
      if (isNaN(d.getTime())) return '';
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  }

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
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'Accept': 'application/json'
      },
      body: form.toString()
    });
    const json = await res.json().catch(() => null);
    if (!res.ok) throw new Error(json?.error || 'Request failed');
    return json;
  }

  function getToken() {
    try { return sessionStorage.getItem(STORAGE_KEY) || ''; } catch { return ''; }
  }

  function setToken(t) {
    try {
      if (!t) sessionStorage.removeItem(STORAGE_KEY);
      else sessionStorage.setItem(STORAGE_KEY, t);
    } catch {
      // ignore
    }
  }

  function mount() {
    // Avoid double-mount
    if (document.getElementById('supportChatFab')) return;

    const fab = ce('button', 'support-chat-fab');
    fab.type = 'button';
    fab.id = 'supportChatFab';
    fab.innerHTML = '<i class="fas fa-comments"></i><span>Live Chat</span>';

    const shell = ce('div', 'support-chat-shell');
    shell.id = 'supportChatShell';

    shell.innerHTML = `
      <div class="support-chat-head">
        <div class="support-chat-title">
          <span>Live Assistance</span>
          <span class="badge" id="supportChatBadge">Farmer</span>
        </div>
        <div class="support-chat-actions">
          <button class="support-chat-btn" type="button" id="supportChatMinBtn">Minimize</button>
          <button class="support-chat-btn" type="button" id="supportChatEndBtn" title="End chat">End</button>
        </div>
      </div>
      <div class="support-chat-body" id="supportChatBody"></div>
      <div class="support-chat-foot" id="supportChatFoot"></div>
    `;

    document.body.appendChild(fab);
    document.body.appendChild(shell);

    const body = qs('#supportChatBody');
    const foot = qs('#supportChatFoot');

    let lastMsgId = 0;
    let pollTimer = null;
    let currentToken = '';

    function open() {
      shell.classList.add('open');
      fab.style.display = 'none';
      boot();
    }

    function close() {
      shell.classList.remove('open');
      fab.style.display = '';
    }

    function renderSetupForm(regions, branches) {
      body.innerHTML = '';
      foot.innerHTML = '';

      const box = ce('div', 'support-chat-bubble');
      box.style.maxWidth = '100%';
      box.innerHTML = `
        <div style="font-weight:700; margin-bottom: 8px;">Connect to a Processor</div>
        <div class="support-chat-note" style="margin-bottom:10px;">Select your Region and Branch so the correct processor can assist you.</div>
        <div class="support-chat-form">
          <div class="support-chat-row">
            <select class="support-chat-select" id="supportChatRegion">
              <option value="">Select Region…</option>
              ${regions.map(r => `<option value="${r.region_id}">${escapeHtml(r.region_name)}</option>`).join('')}
            </select>
          </div>
          <div class="support-chat-row">
            <select class="support-chat-select" id="supportChatBranch" disabled>
              <option value="">Select Branch…</option>
            </select>
          </div>
          <div class="support-chat-row">
            <input class="support-chat-input" id="supportChatName" placeholder="Your name (optional)" maxlength="80" />
          </div>
          <div class="support-chat-row">
            <input class="support-chat-input" id="supportChatContact" placeholder="Contact no/email (optional)" maxlength="120" />
          </div>
          <div style="display:flex; gap:8px; align-items:center;">
            <button class="support-chat-btn primary" type="button" id="supportChatStartBtn">Start Chat</button>
            <span class="support-chat-note">Chat ends when you leave.</span>
          </div>
        </div>
      `;
      body.appendChild(box);
      body.scrollTop = body.scrollHeight;

      const regionSel = qs('#supportChatRegion');
      const branchSel = qs('#supportChatBranch');

      regionSel.addEventListener('change', async () => {
        const rid = parseInt(regionSel.value || '0', 10) || 0;
        branchSel.innerHTML = '<option value="">Select Branch…</option>';
        branchSel.disabled = true;
        if (!rid) return;
        try {
          const resp = await getJson('listSupportBranches', { region_id: rid });
          if (!resp?.success) return;
          const list = Array.isArray(resp.branches) ? resp.branches : [];
          list.forEach(b => {
            const opt = document.createElement('option');
            opt.value = String(b.branch_id);
            opt.textContent = String(b.branch_name);
            branchSel.appendChild(opt);
          });
          branchSel.disabled = false;
        } catch {
          // ignore
        }
      });

      qs('#supportChatStartBtn').addEventListener('click', async () => {
        const rid = parseInt(regionSel.value || '0', 10) || 0;
        const bid = parseInt(branchSel.value || '0', 10) || 0;
        if (!rid || !bid) {
          alert('Please select a Region and Branch.');
          return;
        }
        const name = (qs('#supportChatName')?.value || '').trim();
        const contact = (qs('#supportChatContact')?.value || '').trim();

        try {
          const resp = await postJson('startSupportChat', {
            origin: 'farmer',
            region_id: rid,
            branch_id: bid,
            display_name: name,
            contact: contact
          });
          if (!resp?.success || !resp.token) throw new Error(resp?.error || 'Failed to start chat');
          currentToken = String(resp.token);
          setToken(currentToken);
          lastMsgId = 0;
          // Switch UI into chat mode (hide setup fields)
          body.innerHTML = '<div class="support-chat-empty">Connecting…</div>';
          foot.innerHTML = '';
          await refreshOnce();
          renderComposer();
          schedulePoll();
        } catch (e) {
          alert(e?.message || 'Failed to start chat.');
        }
      });
    }

    function renderComposer() {
      foot.innerHTML = `
        <input class="support-chat-input" id="supportChatInput" placeholder="Type your message…" maxlength="600" />
        <button class="support-chat-btn primary" type="button" id="supportChatSendBtn">Send</button>
      `;

      const input = qs('#supportChatInput');
      const sendBtn = qs('#supportChatSendBtn');

      const send = async () => {
        const text = (input.value || '').trim();
        if (!text) return;
        input.value = '';

        try {
          const resp = await postJson('sendSupportChatMessage', { token: currentToken, message: text });
          if (!resp?.success) throw new Error(resp?.error || 'Failed to send');
          await refreshOnce();
        } catch (e) {
          alert(e?.message || 'Failed to send message.');
        }
      };

      sendBtn.addEventListener('click', send);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          send();
        }
      });
    }

    function renderEndedFooter(reasonText) {
      const reason = (reasonText || '').trim();
      foot.innerHTML = `
        <div style="grid-column: 1 / -1; display:flex; align-items:center; justify-content:space-between; gap:10px;">
          <div class="support-chat-note">${escapeHtml(reason || 'This chat session has ended.')}</div>
          <button class="support-chat-btn" type="button" id="supportChatNewBtn">Start New Chat</button>
        </div>
      `;
      qs('#supportChatNewBtn')?.addEventListener('click', async () => {
        stopPoll();
        setToken('');
        currentToken = '';
        lastMsgId = 0;
        await boot();
      });
    }

    function renderMessages(messages) {
      if (!messages || messages.length === 0) {
        if (!qs('.support-chat-empty', body)) {
          const empty = ce('div', 'support-chat-empty');
          empty.textContent = 'You are connected. Send a message to start.';
          body.appendChild(empty);
        }
        return;
      }

      // remove empty placeholder
      const empty = qs('.support-chat-empty', body);
      if (empty) empty.remove();

      messages.forEach(m => {
        const bubble = ce('div', 'support-chat-bubble');
        const role = String(m.sender_role || '').toLowerCase();
        const isMe = role === 'farmer';
        if (isMe) bubble.classList.add('me');

        bubble.innerHTML = `
          <div>${escapeHtml(String(m.message || ''))}</div>
          <div class="support-chat-meta">${escapeHtml((m.sender_label || (isMe ? 'You' : 'Staff')))} • ${escapeHtml(formatTime(m.created_at))}</div>
        `;
        body.appendChild(bubble);
      });

      body.scrollTop = body.scrollHeight;
    }

    async function refreshOnce() {
      if (!currentToken) return;
      try {
        const resp = await getJson('getSupportChatMessages', { token: currentToken, since_id: lastMsgId });
        if (!resp?.success) return;
        const list = Array.isArray(resp.messages) ? resp.messages : [];
        if (typeof resp.last_id === 'number') lastMsgId = resp.last_id;
        else if (list.length) lastMsgId = Math.max(lastMsgId, ...list.map(x => parseInt(x.id, 10) || 0));

        renderMessages(list);

        const status = String(resp.status || 'open').toLowerCase();
        if (status === 'closed') {
          stopPoll();
          // Keep messages visible, but prevent sending.
          setToken('');
          currentToken = '';

          const closedReason = String(resp.closed_reason || '').toLowerCase();
          const closedBy = String(resp.closed_by_role || '').toLowerCase();
          let reasonText = 'This chat session has ended.';
          if (closedReason === 'inactivity') {
            reasonText = 'This chat session has ended due to inactivity.';
          } else if (closedBy === 'processor') {
            reasonText = 'This chat session has been ended by the processor.';
          } else if (closedBy === 'admin') {
            reasonText = 'This chat session has been ended by the admin.';
          }
          renderEndedFooter(reasonText);
        }
      } catch (e) {
        // Only hard-reset the session for "not found" / expired.
        // For transient failures, keep polling.
        const msg = (e && e.message) ? String(e.message) : '';
        if (msg.toLowerCase().includes('not found') || msg.toLowerCase().includes('expired')) {
          stopPoll();
          setToken('');
          currentToken = '';
          lastMsgId = 0;
          body.innerHTML = '<div class="support-chat-empty">This chat session has expired. Please start a new chat.</div>';
          renderEndedFooter('This chat session has expired.');
        }
      }
    }

    function stopPoll() {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }

    function schedulePoll() {
      stopPoll();
      pollTimer = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        refreshOnce();
      }, 2500);
    }

    async function boot() {
      currentToken = getToken();

      // If token exists, load messages and composer.
      if (currentToken) {
        body.innerHTML = '';
        foot.innerHTML = '';
        await refreshOnce();
        renderComposer();
        schedulePoll();
        return;
      }

      // Otherwise show region/branch selection.
      try {
        const resp = await getJson('listSupportRegions');
        if (!resp?.success) throw new Error('Failed');
        const regions = Array.isArray(resp.regions) ? resp.regions : [];
        renderSetupForm(regions);
      } catch {
        body.innerHTML = '<div class="support-chat-empty">Live chat is unavailable right now.</div>';
        foot.innerHTML = '';
      }
    }

    async function endChat() {
      if (!currentToken) {
        close();
        return;
      }

      stopPoll();
      try {
        await postJson('closeSupportChat', { token: currentToken });
      } catch {
        // ignore
      }
      setToken('');
      currentToken = '';
      lastMsgId = 0;
      body.innerHTML = '<div class="support-chat-empty">Chat ended.</div>';
      foot.innerHTML = '';
      setTimeout(() => {
        close();
      }, 500);
    }

    qs('#supportChatMinBtn').addEventListener('click', () => close());
    qs('#supportChatEndBtn').addEventListener('click', () => endChat());

    fab.addEventListener('click', () => open());

    // Hook: click any element with [data-support-chat="open"]
    document.addEventListener('click', (e) => {
      const el = e.target?.closest?.('[data-support-chat="open"]');
      if (!el) return;
      e.preventDefault();
      open();
    });

    // Auto-open if URL has ?chat=1
    try {
      const u = new URL(window.location.href);
      if (u.searchParams.get('chat') === '1') open();
    } catch {
      // ignore
    }
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Only mount on pages that opt-in
  document.addEventListener('DOMContentLoaded', () => {
    if (!document.querySelector('[data-enable-support-chat="farmer"]')) return;

    // Ensure FontAwesome exists (project already uses it in most pages)
    mount();
  });
})();
