document.addEventListener('DOMContentLoaded', function () {
    setupDropdowns();
    setupAdminAccountNotifications();
    setupRecentActivitySearch();
});

function setupRecentActivitySearch() {
    const search = document.getElementById('recentActivitySearch');
    const table = document.getElementById('recentActivityTable');
    if (!search || !table) return;

    const tbody = table.tBodies && table.tBodies[0];
    if (!tbody) return;

    const noResultsRow = tbody.querySelector('.recent-activity-no-results');

    function normalize(text) {
        return String(text || '').toLowerCase().trim();
    }

    function applyFilter() {
        const q = normalize(search.value);
        let visible = 0;

        const rows = Array.from(tbody.querySelectorAll('tr'))
            .filter(r => !r.classList.contains('recent-activity-no-results'));

        rows.forEach(row => {
            const rowText = normalize(row.innerText);
            const match = q === '' || rowText.includes(q);
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        if (noResultsRow) {
            noResultsRow.style.display = (q !== '' && visible === 0) ? '' : 'none';
        }
    }

    search.addEventListener('input', applyFilter);
    applyFilter();
}

function setupDropdowns() {
    const notifWrapper = document.getElementById('notifWrapper');
    const notifDropdown = document.getElementById('notifDropdown');
    const notifIcon = notifWrapper ? notifWrapper.querySelector('.notif-icon') : null;

    const userProfile = document.querySelector('.user-profile');
    const userDropdown = document.getElementById('userDropdown');

    function closeAll() {
        if (notifDropdown) notifDropdown.classList.remove('show');
        if (userDropdown) userDropdown.classList.remove('show');
        if (notifIcon) notifIcon.setAttribute('aria-expanded', 'false');
        if (userProfile) userProfile.setAttribute('aria-expanded', 'false');
    }

    if (notifIcon && notifDropdown) {
        const toggleNotif = (e) => {
            e.preventDefault();
            e.stopPropagation();

            const willShow = !notifDropdown.classList.contains('show');
            if (userDropdown) userDropdown.classList.remove('show');

            notifDropdown.classList.toggle('show', willShow);
            notifIcon.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        };

        notifIcon.addEventListener('click', toggleNotif);
        notifIcon.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') toggleNotif(e);
        });
    }

    if (userProfile && userDropdown) {
        const toggleUser = (e) => {
            // Allow dropdown links (My Profile / Settings / Logout) to work normally.
            // Only toggle when clicking the profile trigger area, not the dropdown menu itself.
            if (e && e.target && e.target.closest && e.target.closest('.user-dropdown')) {
                return;
            }

            e.stopPropagation();

            const willShow = !userDropdown.classList.contains('show');
            if (notifDropdown) notifDropdown.classList.remove('show');

            userDropdown.classList.toggle('show', willShow);
            userProfile.setAttribute('aria-expanded', willShow ? 'true' : 'false');
        };

        userProfile.addEventListener('click', toggleUser);
        userProfile.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') toggleUser(e);
        });
    }

    document.addEventListener('click', function (e) {
        const clickedNotif = notifWrapper && notifWrapper.contains(e.target);
        const clickedUser = userProfile && userProfile.contains(e.target);
        if (!clickedNotif && !clickedUser) closeAll();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeAll();
    });
}

function setupAdminAccountNotifications() {
    const notifList = document.getElementById('notifList');
    if (!notifList) return;

    const markAllReadBtn = document.getElementById('markAllRead');

    function getBadge() {
        return document.querySelector('.notif-badge');
    }

    function recomputeBadge() {
        const unreadCount = notifList.querySelectorAll('.notif-item.unread').length;
        const badge = getBadge();

        if (unreadCount <= 0) {
            if (badge) badge.remove();
            if (markAllReadBtn) markAllReadBtn.remove();
            return;
        }

        if (badge) {
            badge.textContent = String(unreadCount);
            return;
        }

        const notifIcon = document.querySelector('.notif-icon');
        if (!notifIcon) return;

        const newBadge = document.createElement('span');
        newBadge.className = 'notif-badge pulse';
        newBadge.textContent = String(unreadCount);
        notifIcon.appendChild(newBadge);
    }

    async function setNotifReadState(userId, isRead) {
        const resp = await postJson('adminUpdateAccountNotification', {
            user_id: userId,
            is_read: isRead ? 1 : 0
        });
        if (!resp || !resp.success) throw new Error(resp?.error || resp?.message || 'Failed to update');
        return resp;
    }

    async function deleteNotif(userId) {
        const resp = await postJson('adminDeleteAccountNotification', { user_id: userId });
        if (!resp || !resp.success) throw new Error(resp?.error || resp?.message || 'Failed to delete');
        return resp;
    }

    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', async function (e) {
            e.preventDefault();
            e.stopPropagation();

            const unreadItems = Array.from(notifList.querySelectorAll('.notif-item.unread'));
            if (unreadItems.length === 0) return;

            markAllReadBtn.disabled = true;

            // Optimistic UI
            unreadItems.forEach(item => {
                item.classList.remove('unread');
                item.dataset.isRead = '1';
                const cb = item.querySelector('.notif-checkbox');
                if (cb) cb.checked = true;
            });
            recomputeBadge();

            try {
                const resp = await postJson('adminMarkAllAccountNotificationsRead', {});
                if (!resp || !resp.success) throw new Error(resp?.error || resp?.message || 'Failed to mark all read');
            } catch (err) {
                // revert best-effort
                unreadItems.forEach(item => {
                    item.classList.add('unread');
                    item.dataset.isRead = '0';
                    const cb = item.querySelector('.notif-checkbox');
                    if (cb) cb.checked = false;
                });
                recomputeBadge();
                alert(err.message || 'Failed to update notifications');
            } finally {
                markAllReadBtn.disabled = false;
            }
        });
    }

    notifList.addEventListener('click', async function (e) {
        const item = e.target.closest('.notif-item');
        if (!item) return;

        const userId = parseInt(item.getAttribute('data-user-id') || '0', 10);
        if (!userId) return;

        const deleteBtn = e.target.closest('.notif-delete');
        const checkLabel = e.target.closest('.notif-check');
        const checkbox = e.target.closest('.notif-checkbox');

        if (deleteBtn) {
            e.preventDefault();
            e.stopPropagation();

            // Optimistic remove
            const wasUnread = item.classList.contains('unread');
            item.remove();
            if (notifList.querySelectorAll('.notif-item').length === 0) {
                notifList.innerHTML = `
                    <div class="no-notifications">
                        <i class="fas fa-check-circle"></i>
                        <p>No new notifications</p>
                    </div>
                `;
            }
            if (wasUnread) recomputeBadge();

            try {
                await deleteNotif(userId);
            } catch (err) {
                alert(err.message || 'Failed to delete notification');
            }
            return;
        }

        if (checkLabel || checkbox) {
            e.preventDefault();
            e.stopPropagation();

            const cb = item.querySelector('.notif-checkbox');
            if (!cb) return;

            const nextIsRead = cb.checked ? 0 : 1;
            const wasUnread = item.classList.contains('unread');

            // optimistic UI
            cb.checked = nextIsRead === 1;
            item.classList.toggle('unread', nextIsRead === 0);
            item.dataset.isRead = String(nextIsRead);
            recomputeBadge();

            try {
                await setNotifReadState(userId, nextIsRead === 1);
            } catch (err) {
                // revert
                cb.checked = wasUnread ? false : true;
                item.classList.toggle('unread', wasUnread);
                item.dataset.isRead = wasUnread ? '0' : '1';
                recomputeBadge();
                alert(err.message || 'Failed to update notification');
            }
            return;
        }

        // normal item click: mark as read if unread, then follow href
        const href = item.getAttribute('href');
        const isUnread = item.classList.contains('unread');

        if (isUnread) {
            item.classList.remove('unread');
            item.dataset.isRead = '1';
            const cb = item.querySelector('.notif-checkbox');
            if (cb) cb.checked = true;
            recomputeBadge();

            try {
                await setNotifReadState(userId, true);
            } catch (_) {
                // ignore; navigation still proceeds
            }
        }

        if (href) {
            // allow default navigation
            return;
        }

        e.preventDefault();
    });

    // Initial sync
    recomputeBadge();
}

async function postJson(action, payload) {
    const res = await fetch(`php_helper/api.php?action=${encodeURIComponent(action)}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
    });

    try {
        return await res.json();
    } catch (_) {
        return { success: false, message: 'Invalid server response' };
    }
}
