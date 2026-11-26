(function () {
    'use strict';

    const initialDataEl = document.getElementById('subscriptionInitialData');
    const listEl = document.getElementById('subscriptionList');
    const feedbackEl = document.getElementById('subscriptionFeedback');
    const refreshBtn = document.getElementById('refreshSubscriptionsBtn');
    const modalEl = document.getElementById('subscriptionConfirmModal');

    if (!initialDataEl || !listEl) {
        return;
    }

    let initialPayload = { items: [], indexUrl: '' };
    try {
        initialPayload = JSON.parse(initialDataEl.textContent || '{}');
    } catch (e) {
        initialPayload = { items: [], indexUrl: '' };
    }

    let subscriptions = Array.isArray(initialPayload.items) ? initialPayload.items : [];
    const indexUrl = typeof initialPayload.indexUrl === 'string' ? initialPayload.indexUrl : '';

    const UNCERTAINTY_STORAGE_KEY = 'wecare:sub-uncertain:v1';
    const UNCERTAINTY_TTL_MS = 30 * 60 * 1000;

    let operationSeq = 0;
    let latestAppliedRefreshSeq = 0;
    const inFlightIds = new Set();
    const lastMutationSeqById = new Map();
    const staleRefreshLocks = new Map();
    const uncertainProviderOutcomeIds = new Map();
    let sessionBlockReason = null;
    let activeRefreshCount = 0;
    let pendingAction = null;
    let queuedModalRequest = null;
    let lastTriggerEl = null;

    function getAuthoritativeManagementState(item) {
        if (!item || typeof item !== 'object') {
            return { status: '', managementState: '' };
        }
        return {
            status: String(item.status || '').trim().toLowerCase(),
            managementState: item.management_state ? String(item.management_state).trim().toLowerCase() : '',
        };
    }

    function isOutcomeConfirmedByAuthoritativeState(uncertainEntry, item) {
        if (!uncertainEntry || !item || typeof item !== 'object') {
            return false;
        }
        if (item.operation_in_progress === true) {
            return false;
        }
        const state = getAuthoritativeManagementState(item);
        if (state.managementState === 'cancelled' || state.status === 'cancelled') {
            return true;
        }
        if (state.managementState === 'reconciliation') {
            return true;
        }
        if (uncertainEntry.action === 'pause') {
            return state.status === 'inactive' && state.managementState === 'paused';
        }
        if (uncertainEntry.action === 'resume') {
            return (
                state.status === 'active'
                && state.managementState === 'active'
                && !(uncertainEntry.previousStatus === 'active' && uncertainEntry.previousManagementState === 'active')
            );
        }
        if (uncertainEntry.action === 'cancel') {
            return false;
        }
        if (uncertainEntry.action === 'reconcile') {
            return (
                state.status !== uncertainEntry.previousStatus
                || state.managementState !== uncertainEntry.previousManagementState
            );
        }
        return false;
    }

    function savePersistedUncertainty() {
        try {
            if (typeof window.sessionStorage === 'undefined') {
                return;
            }
            if (uncertainProviderOutcomeIds.size === 0) {
                window.sessionStorage.removeItem(UNCERTAINTY_STORAGE_KEY);
                return;
            }
            const payload = {};
            uncertainProviderOutcomeIds.forEach(function (entry, id) {
                payload[String(id)] = {
                    action: entry.action || 'reconcile',
                    previousStatus: entry.previousStatus || '',
                    previousManagementState: entry.previousManagementState || '',
                    reason: entry.reason || 'uncertain',
                    recordedAt: Number(entry.recordedAt) || Date.now(),
                };
            });
            window.sessionStorage.setItem(UNCERTAINTY_STORAGE_KEY, JSON.stringify(payload));
        } catch (e) {
            // Abaikan error storage bila browser memblokir sessionStorage
        }
    }

    function hydrateUncertaintyFromStorage() {
        try {
            if (typeof window.sessionStorage === 'undefined') {
                return;
            }
            const raw = window.sessionStorage.getItem(UNCERTAINTY_STORAGE_KEY);
            if (!raw) {
                return;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') {
                window.sessionStorage.removeItem(UNCERTAINTY_STORAGE_KEY);
                return;
            }
            const nowMs = Date.now();
            const byId = new Map();
            subscriptions.forEach(function (sub) {
                byId.set(Number(sub.id), sub);
            });

            Object.keys(parsed).forEach(function (key) {
                const subId = Number(key);
                const rec = parsed[key];
                if (!Number.isFinite(subId) || !rec || typeof rec !== 'object') {
                    return;
                }
                const recordedAt = Number(rec.recordedAt) || 0;
                if (nowMs - recordedAt > UNCERTAINTY_TTL_MS) {
                    return;
                }
                const currentItem = byId.get(subId);
                if (!currentItem) {
                    return;
                }
                const entry = {
                    action: typeof rec.action === 'string' ? rec.action : 'reconcile',
                    previousStatus: typeof rec.previousStatus === 'string' ? rec.previousStatus : '',
                    previousManagementState: typeof rec.previousManagementState === 'string' ? rec.previousManagementState : '',
                    settledSeq: 0,
                    reason: typeof rec.reason === 'string' ? rec.reason : 'uncertain',
                    recordedAt: recordedAt,
                };
                if (!isOutcomeConfirmedByAuthoritativeState(entry, currentItem)) {
                    uncertainProviderOutcomeIds.set(subId, entry);
                }
            });

            savePersistedUncertainty();
        } catch (e) {
            // Abaikan error storage bila JSON/sessionStorage tidak tersedia
        }
    }

    function setUncertainOutcome(id, entry) {
        uncertainProviderOutcomeIds.set(id, Object.assign({ recordedAt: Date.now() }, entry));
        savePersistedUncertainty();
    }

    function clearUncertainOutcome(id) {
        if (uncertainProviderOutcomeIds.delete(id)) {
            savePersistedUncertainty();
        }
    }

    const modalTitleEl = document.getElementById('subscriptionConfirmTitle');
    const modalBodyEl = document.getElementById('subscriptionConfirmBody');
    const modalContextEl = document.getElementById('subscriptionConfirmContext');
    const modalConfirmBtn = document.getElementById('subscriptionConfirmProceedBtn');
    const bsModal = modalEl && typeof window.bootstrap !== 'undefined' && window.bootstrap.Modal
        ? new window.bootstrap.Modal(modalEl)
        : null;

    const MODAL_CONFIG = {
        pause: {
            title: 'Jeda Donasi Rutin?',
            body: 'Donasi rutin akan dijeda dan tidak akan menagih siklus baru sampai Anda melanjutkannya kembali.',
            confirmLabel: 'Jeda Donasi',
            confirmClass: 'wc-btn-primary',
        },
        resume: {
            title: 'Lanjutkan Donasi Rutin?',
            body: 'Langganan akan diaktifkan kembali sesuai jadwal dari penyedia pembayaran. Melanjutkan tidak otomatis membuat tagihan baru seketika.',
            confirmLabel: 'Lanjutkan Donasi',
            confirmClass: 'wc-btn-primary',
        },
        cancel: {
            title: 'Batalkan Donasi Rutin?',
            body: 'Pembatalan bersifat final untuk langganan ini dan tidak dapat diaktifkan kembali melalui tombol Lanjutkan. Pembayaran yang sudah berhasil sebelumnya tidak otomatis dikembalikan.',
            confirmLabel: 'Batalkan Donasi',
            confirmClass: 'btn btn-danger rounded-pill px-4 fw-semibold',
        },
    };

    function getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    }

    function formatRupiah(nominal) {
        const numeric = Number(nominal);
        if (!Number.isFinite(numeric)) {
            return 'Rp0';
        }
        return 'Rp' + Math.round(numeric).toLocaleString('id-ID');
    }

    function formatFrequency(frequency) {
        return String(frequency || '').toLowerCase() === 'mingguan' ? 'Mingguan' : 'Bulanan';
    }

    function resolveSubscriptionUiState(item) {
        const localStatus = String(item && item.status ? item.status : '').trim().toLowerCase();
        const mgmtState = item && item.management_state ? String(item.management_state).trim().toLowerCase() : null;

        if (mgmtState === 'cancelled' || localStatus === 'cancelled') {
            return {
                label: 'Dibatalkan',
                badgeClass: 'wc-badge wc-badge-cancelled',
                actions: [],
            };
        }

        if (mgmtState === 'reconciliation') {
            return {
                label: 'Menunggu Verifikasi',
                badgeClass: 'wc-badge wc-badge-biasa',
                actions: ['reconcile'],
            };
        }

        if (localStatus === 'inactive' && mgmtState === 'paused') {
            return {
                label: 'Dijeda',
                badgeClass: 'wc-badge wc-badge-paused',
                actions: ['resume', 'cancel'],
            };
        }

        if (localStatus === 'active' && (mgmtState === null || mgmtState === 'active')) {
            return {
                label: 'Aktif',
                badgeClass: 'wc-badge wc-badge-terjadwal',
                actions: ['pause', 'cancel'],
            };
        }

        if (localStatus === 'inactive' && mgmtState === null) {
            return {
                label: 'Status Belum Dipastikan',
                badgeClass: 'wc-badge wc-badge-neutral',
                actions: ['reconcile'],
            };
        }

        return {
            label: 'Status Belum Dipastikan',
            badgeClass: 'wc-badge wc-badge-neutral',
            actions: [],
        };
    }

    function getEffectiveActions(item) {
        const uiState = resolveSubscriptionUiState(item);
        const id = Number(item && item.id);
        if (!Number.isFinite(id)) {
            return [];
        }

        if (uncertainProviderOutcomeIds.has(id) && uiState.actions.length > 0) {
            return ['reconcile'];
        }

        return uiState.actions;
    }

    function resolveScheduleDisplayText(item) {
        const localStatus = String(item && item.status ? item.status : '').trim().toLowerCase();
        const mgmtState = item && item.management_state ? String(item.management_state).trim().toLowerCase() : null;
        const schedule = item && typeof item.schedule === 'object' && item.schedule !== null ? item.schedule : {};
        const schedStatus = String(schedule.status || 'unavailable');

        if (mgmtState === 'cancelled' || localStatus === 'cancelled') {
            return 'Tidak ada penagihan otomatis berikutnya.';
        }

        if (mgmtState === 'reconciliation') {
            return 'Jadwal menunggu verifikasi.';
        }

        if (mgmtState === 'paused') {
            return 'Donasi rutin sedang dijeda.';
        }

        if (localStatus !== 'active') {
            return 'Jadwal belum dapat dipastikan.';
        }

        if (schedStatus === 'confirmed' && schedule.is_displayable_upcoming === true) {
            if (typeof schedule.display_wib === 'string' && schedule.display_wib.trim() !== '') {
                return 'Jadwal terakhir tercatat: ' + schedule.display_wib;
            }
            if (typeof item.next_execution_at === 'string' && item.next_execution_at.trim() !== '') {
                const parsed = new Date(item.next_execution_at);
                if (!Number.isNaN(parsed.getTime())) {
                    const formatted = new Intl.DateTimeFormat('id-ID', {
                        timeZone: 'Asia/Jakarta',
                        day: 'numeric',
                        month: 'long',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: false,
                    }).format(parsed);
                    return 'Jadwal terakhir tercatat: ' + formatted + ' WIB';
                }
            }
        }

        if (schedStatus === 'historical_unverified') {
            return 'Jadwal berikutnya belum terverifikasi.';
        }

        if (schedStatus === 'past_awaiting_refresh' || schedStatus === 'stale_preserved' || schedStatus === 'awaiting_verification') {
            return 'Jadwal menunggu verifikasi.';
        }

        return 'Jadwal belum dapat dipastikan.';
    }

    function showFeedback(variant, message) {
        if (!feedbackEl) {
            return;
        }
        feedbackEl.className = 'alert small mb-3 alert-' + variant;
        feedbackEl.textContent = message;
    }

    function createActionButton(action, item, disabled) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.dataset.subAction = action;
        btn.dataset.subscriptionId = String(item.id);
        btn.disabled = disabled;

        if (action === 'pause') {
            btn.className = 'btn btn-sm wc-btn-outline';
            btn.textContent = 'Jeda';
        } else if (action === 'resume') {
            btn.className = 'btn btn-sm wc-btn-primary';
            btn.textContent = 'Lanjutkan';
        } else if (action === 'reconcile') {
            btn.className = 'btn btn-sm wc-btn-outline';
            btn.textContent = 'Periksa Status';
        } else {
            btn.className = 'btn btn-sm wc-btn-danger-outline';
            btn.textContent = 'Batalkan';
        }

        return btn;
    }

    function renderSubscriptions() {
        while (listEl.firstChild) {
            listEl.removeChild(listEl.firstChild);
        }

        if (refreshBtn) {
            refreshBtn.disabled = Boolean(sessionBlockReason) || activeRefreshCount > 0;
        }

        if (!subscriptions.length) {
            const empty = document.createElement('p');
            empty.className = 'small text-secondary mb-0';
            empty.dataset.role = 'empty-state';
            empty.textContent = 'Belum ada donasi rutin terdaftar.';
            listEl.appendChild(empty);
            return;
        }

        subscriptions.forEach(function (item) {
            const id = Number(item.id);
            const uiState = resolveSubscriptionUiState(item);
            const effectiveActions = getEffectiveActions(item);
            const isBusy = inFlightIds.has(id);
            const staleLock = staleRefreshLocks.get(id) || null;
            const uncertainEntry = uncertainProviderOutcomeIds.get(id) || null;
            const isGuardOccupied = item.operation_in_progress === true;

            const isDisabledForAll = Boolean(sessionBlockReason) || isBusy || staleLock !== null || isGuardOccupied;

            const row = document.createElement('div');
            row.className = 'wc-sub-item border-bottom pb-3 mb-3';
            row.dataset.subscriptionId = String(id);

            const header = document.createElement('div');
            header.className = 'd-flex justify-content-between align-items-start gap-2 mb-1';

            const title = document.createElement('p');
            title.className = 'fw-semibold mb-0';
            title.textContent = item.campaign_title || 'Campaign tidak tersedia';

            const badge = document.createElement('span');
            badge.className = uiState.badgeClass + ' text-nowrap';
            badge.dataset.role = 'status-badge';
            badge.textContent = uiState.label;

            header.appendChild(title);
            header.appendChild(badge);
            row.appendChild(header);

            const summary = document.createElement('p');
            summary.className = 'small text-secondary mb-1';
            summary.textContent = formatRupiah(item.nominal) + ' \u00b7 ' + formatFrequency(item.frequency);
            row.appendChild(summary);

            const scheduleP = document.createElement('p');
            scheduleP.className = 'small text-secondary mb-2';
            scheduleP.dataset.role = 'schedule-text';
            scheduleP.textContent = resolveScheduleDisplayText(item);
            row.appendChild(scheduleP);

            if (sessionBlockReason === 'auth') {
                const sessionNote = document.createElement('p');
                sessionNote.className = 'small text-danger mb-2';
                sessionNote.dataset.role = 'session-notice';
                sessionNote.textContent = 'Sesi login telah berakhir. Muat ulang halaman dan masuk kembali sebelum melanjutkan.';
                row.appendChild(sessionNote);
            } else if (sessionBlockReason === 'csrf') {
                const csrfNote = document.createElement('p');
                csrfNote.className = 'small text-danger mb-2';
                csrfNote.dataset.role = 'session-notice';
                csrfNote.textContent = 'Token keamanan halaman kedaluwarsa. Muat ulang halaman sebelum mencoba kembali.';
                row.appendChild(csrfNote);
            } else if (isBusy) {
                const loadingNote = document.createElement('p');
                loadingNote.className = 'small text-secondary mb-2';
                loadingNote.dataset.role = 'loading-notice';
                loadingNote.textContent = 'Memproses permintaan ke server...';
                row.appendChild(loadingNote);
            } else if (isGuardOccupied) {
                const guardNote = document.createElement('p');
                guardNote.className = 'small wc-notice-warning mb-2';
                guardNote.dataset.role = 'guard-notice';
                guardNote.textContent = 'Operasi lain sedang diproses di server. Gunakan Muat Ulang untuk memeriksa kembali.';
                row.appendChild(guardNote);
            } else if (staleLock !== null) {
                const lockedNote = document.createElement('p');
                lockedNote.className = 'small wc-notice-warning mb-2';
                lockedNote.dataset.role = 'stale-lock-notice';
                lockedNote.textContent = staleLock.message;
                row.appendChild(lockedNote);
            } else if (uncertainEntry !== null) {
                const uncertainNote = document.createElement('p');
                uncertainNote.className = 'small wc-notice-warning mb-2';
                uncertainNote.dataset.role = 'uncertain-notice';
                uncertainNote.textContent = 'Status penyedia belum dapat dipastikan hanya dari daftar lokal. Gunakan Periksa Status untuk memeriksa penyedia secara aman.';
                row.appendChild(uncertainNote);
            }

            if (effectiveActions.length > 0) {
                const actionsWrap = document.createElement('div');
                actionsWrap.className = 'd-flex flex-wrap gap-2';
                actionsWrap.dataset.role = 'actions';
                effectiveActions.forEach(function (action) {
                    actionsWrap.appendChild(createActionButton(action, item, isDisabledForAll));
                });
                row.appendChild(actionsWrap);
            }

            listEl.appendChild(row);
        });
    }

    async function fetchWithTimeout(url, options, timeoutMs) {
        const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        const timerId = controller
            ? window.setTimeout(function () {
                  controller.abort();
              }, timeoutMs || 20000)
            : null;

        try {
            const merged = Object.assign({ credentials: 'same-origin' }, options);
            if (controller) {
                merged.signal = controller.signal;
            }
            return await window.fetch(url, merged);
        } finally {
            if (timerId !== null) {
                window.clearTimeout(timerId);
            }
        }
    }

    async function safeParseJson(response) {
        const contentType = response.headers ? String(response.headers.get('content-type') || '') : '';
        if (contentType.toLowerCase().indexOf('application/json') === -1) {
            return null;
        }
        try {
            const parsed = await response.json();
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function isValidSubscriptionsPayload(data) {
        if (!data || typeof data !== 'object' || data.success !== true || !Array.isArray(data.subscriptions)) {
            return false;
        }
        return data.subscriptions.every(function (sub) {
            return (
                sub !== null &&
                typeof sub === 'object' &&
                Number.isFinite(Number(sub.id)) &&
                Number(sub.id) > 0 &&
                typeof sub.status === 'string' &&
                sub.status.trim() !== ''
            );
        });
    }

    function isLoginRedirect(response) {
        if (!response || !response.redirected) {
            return false;
        }
        const url = String(response.url || '').toLowerCase();
        return url === '' || url.indexOf('/login') !== -1 || url.indexOf('/auth/') !== -1;
    }

    function normalizeSubscriptionItem(sub) {
        const nominal = Number(sub.nominal);
        return Object.assign({}, sub, {
            nominal_formatted: formatRupiah(nominal),
            frequency_label: formatFrequency(sub.frequency),
        });
    }

    async function refreshSubscriptionsList(options) {
        const opts = options || {};
        if (!indexUrl || sessionBlockReason) {
            return false;
        }

        const reqSeq = ++operationSeq;
        activeRefreshCount += 1;
        renderSubscriptions();

        try {
            const response = await fetchWithTimeout(indexUrl, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }, 20000);

            if (response.status === 401 || isLoginRedirect(response)) {
                sessionBlockReason = 'auth';
                if (!opts.suppressErrorFeedback) {
                    showFeedback('warning', 'Sesi Anda telah berakhir. Muat ulang halaman dan masuk kembali.');
                }
                renderSubscriptions();
                return false;
            }

            if (response.status === 419) {
                sessionBlockReason = 'csrf';
                if (!opts.suppressErrorFeedback) {
                    showFeedback('warning', 'Token keamanan halaman kedaluwarsa. Muat ulang halaman sebelum menyegarkan.');
                }
                renderSubscriptions();
                return false;
            }

            const data = await safeParseJson(response);
            if (!response.ok || !isValidSubscriptionsPayload(data)) {
                if (!opts.suppressErrorFeedback) {
                    showFeedback('warning', 'Daftar donasi rutin belum dapat disegarkan. Status lokal terakhir tetap dipertahankan.');
                }
                return false;
            }

            if (reqSeq < latestAppliedRefreshSeq) {
                return false;
            }
            latestAppliedRefreshSeq = reqSeq;

            const existingById = new Map();
            subscriptions.forEach(function (existing) {
                existingById.set(Number(existing.id), existing);
            });

            const nextList = [];
            data.subscriptions.forEach(function (rawSub) {
                const subId = Number(rawSub.id);
                const mutationBarrierSeq = lastMutationSeqById.get(subId) || 0;
                const staleLock = staleRefreshLocks.get(subId);

                if (inFlightIds.has(subId) || reqSeq <= mutationBarrierSeq) {
                    if (existingById.has(subId)) {
                        nextList.push(existingById.get(subId));
                    } else {
                        nextList.push(normalizeSubscriptionItem(rawSub));
                    }
                    return;
                }

                const normalized = normalizeSubscriptionItem(rawSub);
                nextList.push(normalized);

                if (staleLock && reqSeq > staleLock.requiredAfterSeq) {
                    staleRefreshLocks.delete(subId);
                }

                const uncertain = uncertainProviderOutcomeIds.get(subId);
                if (uncertain && reqSeq > uncertain.settledSeq) {
                    if (isOutcomeConfirmedByAuthoritativeState(uncertain, normalized)) {
                        clearUncertainOutcome(subId);
                    }
                }
            });

            subscriptions = nextList;
            renderSubscriptions();
            return true;
        } catch (e) {
            if (!opts.suppressErrorFeedback) {
                showFeedback('warning', 'Koneksi gagal saat menyegarkan daftar donasi rutin.');
            }
            return false;
        } finally {
            activeRefreshCount = Math.max(0, activeRefreshCount - 1);
            renderSubscriptions();
        }
    }

    async function executeManagementAction(subscriptionId, action) {
        const id = Number(subscriptionId);
        if (
            sessionBlockReason ||
            inFlightIds.has(id) ||
            staleRefreshLocks.has(id)
        ) {
            return;
        }

        const item = subscriptions.find(function (sub) {
            return Number(sub.id) === id;
        });
        if (!item || item.operation_in_progress === true) {
            return;
        }

        const effectiveActions = getEffectiveActions(item);
        if (effectiveActions.indexOf(action) === -1) {
            showFeedback('warning', 'Aksi yang dipilih tidak lagi tersedia untuk status langganan terbaru.');
            renderSubscriptions();
            return;
        }

        if (!item.endpoints || !item.endpoints[action]) {
            showFeedback('danger', 'Endpoint operasi untuk langganan ini tidak ditemukan.');
            return;
        }

        const startSeq = ++operationSeq;
        lastMutationSeqById.set(id, startSeq);
        const stateBeforeMutation = getAuthoritativeManagementState(item);

        inFlightIds.add(id);
        renderSubscriptions();

        let postWasDispatched = false;
        let isDefinitiveSuccessAck = false;
        let markUncertain = false;

        try {
            postWasDispatched = true;
            const response = await fetchWithTimeout(item.endpoints[action], {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            }, 20000);

            if (response.status === 401 || isLoginRedirect(response)) {
                sessionBlockReason = 'auth';
                showFeedback('warning', 'Sesi Anda telah berakhir. Muat ulang halaman dan masuk kembali sebelum mengirim permintaan.');
                return;
            }

            if (response.status === 419) {
                sessionBlockReason = 'csrf';
                showFeedback('warning', 'Sesi keamanan halaman kedaluwarsa. Muat ulang halaman sebelum mencoba kembali.');
                return;
            }

            const data = await safeParseJson(response);
            const serverMessage = data && typeof data.message === 'string' && data.message.trim() !== ''
                ? data.message.trim()
                : null;

            const settledSeq = ++operationSeq;
            lastMutationSeqById.set(id, settledSeq);

            if (response.status === 200 && data && data.success === true) {
                isDefinitiveSuccessAck = true;
                clearUncertainOutcome(id);
                staleRefreshLocks.set(id, {
                    requiredAfterSeq: settledSeq,
                    message: 'Menunggu pembaruan daftar \u2014 klik Muat Ulang.',
                });

                inFlightIds.delete(id);
                const refreshed = await refreshSubscriptionsList({ suppressErrorFeedback: true });
                if (refreshed && !staleRefreshLocks.has(id)) {
                    showFeedback('success', serverMessage || 'Status donasi rutin berhasil diperbarui.');
                } else {
                    showFeedback(
                        'warning',
                        'Operasi diterima server, tetapi daftar terbaru gagal dimuat. Gunakan Muat Ulang untuk menyegarkan status sebelum melakukan aksi lain.'
                    );
                }
                return;
            }

            staleRefreshLocks.set(id, {
                requiredAfterSeq: settledSeq,
                message: 'Menunggu pembaruan daftar \u2014 klik Muat Ulang.',
            });

            if (response.status === 200) {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: 'invalid_200',
                });
                showFeedback(
                    'warning',
                    serverMessage || 'Respons server tidak lengkap. Status belum dapat dipastikan; muat ulang dan gunakan Periksa Status jika diperlukan.'
                );
            } else if (response.status === 202) {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: 'reconciliation_202',
                });
                showFeedback(
                    'warning',
                    serverMessage || 'Status belum dapat dipastikan di penyedia. Gunakan Periksa Status setelah daftar disegarkan.'
                );
            } else if (response.status === 409) {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: data && data.operation_in_progress === true ? 'guard_occupied_409' : 'conflict_409',
                });
                if (data && data.operation_in_progress === true) {
                    item.operation_in_progress = true;
                    showFeedback(
                        'warning',
                        serverMessage || 'Operasi lain sedang berjalan di server. Daftar disegarkan dan tombol dinonaktifkan sementara.'
                    );
                } else {
                    showFeedback(
                        'warning',
                        serverMessage || 'Terjadi konflik status langganan. Gunakan Periksa Status untuk memverifikasi kondisi penyedia.'
                    );
                }
            } else if ((response.status === 403 || response.status === 404 || response.status === 422) && data) {
                showFeedback(
                    'danger',
                    serverMessage || 'Operasi ditolak atau tidak berlaku untuk status langganan saat ini.'
                );
            } else if (response.status >= 400 && response.status < 500) {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: 'unexpected_4xx',
                });
                showFeedback(
                    'warning',
                    serverMessage || 'Permintaan tidak dapat diproses saat ini. Gunakan Periksa Status untuk memastikan status terbaru.'
                );
            } else {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: 'server_error_or_non_json',
                });
                showFeedback(
                    'warning',
                    serverMessage || 'Layanan verifikasi penyedia sedang tidak tersedia. Gunakan Muat Ulang dan Periksa Status untuk memastikan status.'
                );
            }

            inFlightIds.delete(id);
            await refreshSubscriptionsList({ suppressErrorFeedback: true });
        } catch (err) {
            const settledSeq = ++operationSeq;
            lastMutationSeqById.set(id, settledSeq);
            if (postWasDispatched && !isDefinitiveSuccessAck) {
                markUncertain = true;
                setUncertainOutcome(id, {
                    action: action,
                    previousStatus: stateBeforeMutation.status,
                    previousManagementState: stateBeforeMutation.managementState,
                    settledSeq: settledSeq,
                    reason: 'network_or_timeout',
                });
                staleRefreshLocks.set(id, {
                    requiredAfterSeq: settledSeq,
                    message: 'Status belum dapat dipastikan \u2014 klik Muat Ulang.',
                });
            }
            const isAbort = err && err.name === 'AbortError';
            showFeedback(
                'warning',
                isAbort
                    ? 'Permintaan melewati batas waktu. Jangan kirim ulang sebelum memuat ulang daftar dan memeriksa status.'
                    : 'Gangguan jaringan terjadi. Status lokal tidak diasumsikan aman; muat ulang daftar dan gunakan Periksa Status bila perlu.'
            );
            inFlightIds.delete(id);
            await refreshSubscriptionsList({ suppressErrorFeedback: true });
        } finally {
            inFlightIds.delete(id);
            if (markUncertain && !uncertainProviderOutcomeIds.has(id)) {
                // Terjaga oleh logika refresh apabila state masih identik
            }
            renderSubscriptions();
        }
    }

    function openConfirmModal(item, action, triggerEl) {
        const cfg = MODAL_CONFIG[action];
        if (!cfg || !bsModal) {
            return;
        }

        if (modalEl && (modalEl.classList.contains('show') || bsModal._isTransitioning)) {
            queuedModalRequest = { item: item, action: action, triggerEl: triggerEl || null };
            return;
        }

        pendingAction = { id: Number(item.id), action: action };
        lastTriggerEl = triggerEl || null;

        modalTitleEl.textContent = cfg.title;
        modalBodyEl.textContent = cfg.body;
        modalContextEl.textContent =
            (item.campaign_title || 'Campaign tidak tersedia') +
            ' \u00b7 ' +
            formatRupiah(item.nominal) +
            ' (' +
            formatFrequency(item.frequency) +
            ')';
        modalConfirmBtn.textContent = cfg.confirmLabel;
        modalConfirmBtn.className = cfg.confirmClass;
        modalConfirmBtn.disabled = false;

        bsModal.show();
    }

    function hideConfirmModal() {
        if (!bsModal) {
            return;
        }
        bsModal.hide();
        if (modalEl && modalEl.classList.contains('show')) {
            modalEl.addEventListener('shown.bs.modal', function () {
                bsModal.hide();
            }, { once: true });
        }
    }

    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function () {
            pendingAction = null;
            if (queuedModalRequest) {
                const nextReq = queuedModalRequest;
                queuedModalRequest = null;
                openConfirmModal(nextReq.item, nextReq.action, nextReq.triggerEl);
                return;
            }
            const trigger = lastTriggerEl;
            lastTriggerEl = null;
            if (trigger && typeof trigger.focus === 'function' && document.contains(trigger)) {
                trigger.focus();
            }
        });
    }

    if (modalConfirmBtn) {
        modalConfirmBtn.addEventListener('click', function () {
            if (!pendingAction) {
                return;
            }
            const chosen = pendingAction;
            pendingAction = null;
            modalConfirmBtn.disabled = true;
            hideConfirmModal();
            executeManagementAction(chosen.id, chosen.action);
        });
    }

    listEl.addEventListener('click', function (event) {
        const btn = event.target.closest('button[data-sub-action]');
        if (!btn) {
            return;
        }

        const action = btn.dataset.subAction;
        const id = Number(btn.dataset.subscriptionId);
        if (
            !action ||
            !Number.isFinite(id) ||
            Boolean(sessionBlockReason) ||
            inFlightIds.has(id) ||
            staleRefreshLocks.has(id)
        ) {
            return;
        }

        const item = subscriptions.find(function (sub) {
            return Number(sub.id) === id;
        });
        if (!item || item.operation_in_progress === true) {
            return;
        }

        if (getEffectiveActions(item).indexOf(action) === -1) {
            return;
        }

        if (action === 'reconcile') {
            executeManagementAction(id, 'reconcile');
            return;
        }

        openConfirmModal(item, action, btn);
    });

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            refreshSubscriptionsList({ suppressErrorFeedback: false });
        });
    }

    hydrateUncertaintyFromStorage();
    renderSubscriptions();
})();
