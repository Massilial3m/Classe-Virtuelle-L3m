/* L3M TOC Inline Navigation */

// Redirect teachers from quiz view to quiz edit page
(function () {
    var body = document.body;
    var isTeacher = body.classList.contains('roleshortname-editingteacher') ||
                    body.classList.contains('roleshortname-teacher');
    if (isTeacher && window.location.pathname.indexOf('/mod/quiz/view.php') !== -1) {
        var params = new URLSearchParams(window.location.search);
        var cmid = params.get('id');
        if (cmid) {
            window.location.replace(window.location.origin + '/mod/quiz/edit.php?cmid=' + cmid);
        }
    }
})();

(function () {
    var courseIdMatch = window.location.search.match(/[?&]id=(\d+)/);
    var courseId = courseIdMatch ? courseIdMatch[1] : '0';
    var storageKey = 'l3m_toc_' + courseId;

    // ---- MINUTERIE D'ÉTUDE ----
    var timeStorageKey = 'l3m_time_' + courseId;
    var _timerInterval = null;
    var _sessionSeconds = 0;

    function _getTotalSeconds() {
        try { return parseInt(localStorage.getItem(timeStorageKey) || '0', 10); } catch(e) { return 0; }
    }
    function _saveTotalSeconds(s) {
        try { localStorage.setItem(timeStorageKey, String(s)); } catch(e) {}
    }
    function _fmtTime(s) {
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
        if (h > 0) return h + 'h ' + (m < 10 ? '0' : '') + m + 'min';
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    }
    function _updateTimerDisplay() {
        var el = document.getElementById('l3m-timer-display');
        if (el) el.textContent = '⏱ ' + _fmtTime(_sessionSeconds);
    }
    function startTimer() {
        if (_timerInterval) return;
        _timerInterval = setInterval(function() {
            _sessionSeconds++;
            _updateTimerDisplay();
        }, 1000);
    }
    function pauseTimer() {
        if (!_timerInterval) return;
        clearInterval(_timerInterval);
        _timerInterval = null;
        _saveTotalSeconds(_getTotalSeconds() + _sessionSeconds);
    }
    function resetSessionTimer() {
        pauseTimer();
        _sessionSeconds = 0;
        _updateTimerDisplay();
    }

    // ---- NOTES ----
    var notesStorageKey = 'l3m_notes_' + courseId;
    var _currentActivityId = null;
    var _currentActivityName = '';

    function getActivityIdFromUrl(url) {
        var m = (url || '').match(/[?&]id=(\d+)/);
        return m ? m[1] : (url || '').replace(/[^a-z0-9]/gi, '_').substring(0, 40);
    }
    function _allNotes() {
        try { return JSON.parse(localStorage.getItem(notesStorageKey) || '{}'); } catch(e) { return {}; }
    }
    function getNotesForActivity(actId) { return _allNotes()[actId] || []; }
    function saveNote(actId, text) {
        var all = _allNotes();
        if (!all[actId]) all[actId] = [];
        all[actId].unshift({ text: text, ts: Date.now() });
        try { localStorage.setItem(notesStorageKey, JSON.stringify(all)); } catch(e) {}
    }
    function deleteNote(actId, idx) {
        var all = _allNotes();
        if (all[actId]) { all[actId].splice(idx, 1); try { localStorage.setItem(notesStorageKey, JSON.stringify(all)); } catch(e) {} }
    }
    function _fmtDate(ts) {
        var d = new Date(ts);
        return d.toLocaleDateString('fr-FR', {day:'2-digit', month:'short'}) + ' ' +
               d.toLocaleTimeString('fr-FR', {hour:'2-digit', minute:'2-digit'});
    }
    function renderNotesList(actId) {
        var list = document.getElementById('l3m-notes-list');
        if (!list) return;
        var notes = getNotesForActivity(actId);
        if (!notes.length) { list.innerHTML = '<p class="l3m-notes-empty">Aucune note pour cette activité.</p>'; return; }
        list.innerHTML = notes.map(function(n, i) {
            return '<div class="l3m-note-item">' +
                '<div class="l3m-note-meta">' + _fmtDate(n.ts) +
                '<button class="l3m-note-delete" data-i="' + i + '" title="Supprimer">&times;</button></div>' +
                '<div class="l3m-note-text">' + n.text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>') + '</div>' +
                '</div>';
        }).join('');
        list.querySelectorAll('.l3m-note-delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                deleteNote(actId, parseInt(this.dataset.i));
                renderNotesList(actId);
            });
        });
    }
    function _ensureNotesPanel() {
        var panel = document.getElementById('l3m-notes-panel');
        if (panel) return panel;
        panel = document.createElement('div');
        panel.id = 'l3m-notes-panel';
        panel.innerHTML =
            '<div class="l3m-notes-header">' +
            '<span class="l3m-notes-title">📝 Notes — <em id="l3m-notes-actname">cette activité</em></span>' +
            '<button id="l3m-notes-close" title="Fermer">&times;</button>' +
            '</div>' +
            '<div class="l3m-notes-body">' +
            '<textarea id="l3m-notes-input" placeholder="Écrivez vos notes ici… (Ctrl+Entrée pour enregistrer)"></textarea>' +
            '<button id="l3m-notes-save">Enregistrer</button>' +
            '<div id="l3m-notes-list"></div>' +
            '</div>';
        document.body.appendChild(panel);
        panel.querySelector('#l3m-notes-close').addEventListener('click', function() {
            panel.classList.remove('active');
            var nb = document.getElementById('l3m-notes-btn');
            if (nb) nb.classList.remove('active');
        });
        panel.querySelector('#l3m-notes-save').addEventListener('click', function() {
            var inp = document.getElementById('l3m-notes-input');
            var txt = inp.value.trim();
            if (!txt || !_currentActivityId) return;
            saveNote(_currentActivityId, txt);
            inp.value = '';
            renderNotesList(_currentActivityId);
        });
        panel.querySelector('#l3m-notes-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) panel.querySelector('#l3m-notes-save').click();
        });
        return panel;
    }
    function showNotesPanel(actId, actName) {
        _currentActivityId = actId;
        _currentActivityName = actName;
        var panel = _ensureNotesPanel();
        var em = document.getElementById('l3m-notes-actname');
        if (em) em.textContent = actName || 'cette activité';
        renderNotesList(actId);
        panel.classList.add('active');
    }
    function hideNotesPanel() {
        var panel = document.getElementById('l3m-notes-panel');
        if (panel) panel.classList.remove('active');
    }

    // Inject resume button CSS once
    (function injectResumeStyle() {
        var style = document.createElement('style');
        style.textContent =
            '#l3m-resume-btn {' +
            '  display:flex; align-items:center; gap:10px;' +
            '  width:100%; box-sizing:border-box; margin-bottom:10px; padding:10px 14px;' +
            '  background:linear-gradient(135deg,#C5A900,#a08900);' +
            '  color:#000; border:none; border-radius:6px; overflow:hidden;' +
            '  font-family:Montserrat,sans-serif;' +
            '  cursor:pointer; text-align:left; box-shadow:0 2px 8px rgba(197,169,0,.35);' +
            '  transition:transform .15s,box-shadow .15s;' +
            '}' +
            '#l3m-resume-btn:hover { transform:translateY(-1px); box-shadow:0 4px 14px rgba(197,169,0,.5); }' +
            '#l3m-resume-btn .l3m-resume-icon { font-size:18px; flex-shrink:0; line-height:1; }' +
            '#l3m-resume-btn .l3m-resume-text { display:flex; flex-direction:column; min-width:0; flex:1; }' +
            '#l3m-resume-btn .l3m-resume-label { font-weight:800; font-size:12px; text-transform:uppercase; letter-spacing:.05em; line-height:1.2; }' +
            '#l3m-resume-btn em { font-style:normal; font-weight:600; font-size:13px; opacity:.85;' +
            '  white-space:normal; overflow:visible; display:block; line-height:1.4; }';
        (document.head || document.documentElement).appendChild(style);
    })();

    (function injectNotesStyle() {
        var s = document.createElement('style');
        s.textContent =
            '#l3m-iframe-toolbar { display:flex; align-items:center; justify-content:flex-end; gap:6px;' +
            '  padding:6px 8px; background:#111; border-bottom:2px solid #C5A900; }' +
            '#l3m-notes-btn { display:flex; align-items:center; gap:5px; padding:5px 12px;' +
            '  background:#C5A900; color:#000; border:none; border-radius:4px;' +
            '  font-family:Montserrat,sans-serif; font-weight:700; font-size:12px; cursor:pointer;' +
            '  transition:background .15s; }' +
            '#l3m-notes-btn:hover { background:#a08900; }' +
            '#l3m-notes-btn.active { background:#fff; color:#000; }' +
            '#l3m-iframe-close-btn { background:transparent; border:none; color:#fff;' +
            '  font-size:22px; line-height:1; cursor:pointer; padding:2px 6px; border-radius:4px;' +
            '  transition:background .15s; }' +
            '#l3m-iframe-close-btn:hover { background:rgba(255,255,255,.15); }' +
            '#l3m-notes-panel { display:none; position:fixed; bottom:20px; right:20px; z-index:99999;' +
            '  width:340px; max-height:70vh; background:#fff; border-radius:10px;' +
            '  box-shadow:0 8px 32px rgba(0,0,0,.22); overflow:hidden; flex-direction:column; }' +
            '#l3m-notes-panel.active { display:flex; }' +
            '.l3m-notes-header { display:flex; align-items:center; justify-content:space-between;' +
            '  padding:10px 14px; background:#1a1a1a; border-bottom:2px solid #C5A900; flex-shrink:0; }' +
            '.l3m-notes-title { font-family:Montserrat,sans-serif; font-weight:700; font-size:12px; color:#fff;' +
            '  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; min-width:0; }' +
            '.l3m-notes-title em { font-style:normal; color:#C5A900; }' +
            '#l3m-notes-close { background:transparent; border:none; font-size:20px; cursor:pointer;' +
            '  color:#aaa; line-height:1; padding:0 0 0 8px; flex-shrink:0; }' +
            '#l3m-notes-close:hover { color:#fff; }' +
            '.l3m-notes-body { padding:12px 14px; overflow-y:auto; display:flex; flex-direction:column; gap:8px; }' +
            '#l3m-notes-input { width:100%; box-sizing:border-box; padding:10px;' +
            '  border:1px solid #ddd; border-radius:6px; font-family:"Open Sans",sans-serif;' +
            '  font-size:13px; resize:vertical; outline:none; min-height:80px;' +
            '  transition:border-color .15s; }' +
            '#l3m-notes-input:focus { border-color:#C5A900; box-shadow:0 0 0 2px rgba(197,169,0,.2); }' +
            '#l3m-notes-save { padding:7px 18px; background:#C5A900; color:#000; border:none; border-radius:4px;' +
            '  font-family:Montserrat,sans-serif; font-weight:700; font-size:12px; cursor:pointer;' +
            '  align-self:flex-start; transition:background .15s; }' +
            '#l3m-notes-save:hover { background:#a08900; }' +
            '#l3m-notes-list { display:flex; flex-direction:column; gap:8px; }' +
            '.l3m-notes-empty { color:#999; font-size:13px; font-style:italic; margin:0; }' +
            '.l3m-note-item { background:#fafafa; border:1px solid #eee; border-radius:6px; padding:10px 12px; }' +
            '.l3m-note-meta { display:flex; justify-content:space-between; align-items:center;' +
            '  font-size:11px; color:#999; margin-bottom:6px; }' +
            '.l3m-note-delete { background:transparent; border:none; color:#ccc; font-size:16px;' +
            '  cursor:pointer; line-height:1; padding:0; transition:color .15s; }' +
            '.l3m-note-delete:hover { color:#e53935; }' +
            '.l3m-note-text { font-size:13px; color:#333; line-height:1.5; white-space:pre-wrap; word-break:break-word; }' +
            // Keyboard shortcuts popup
            '#l3m-timer-display { font-family:monospace; font-size:13px; color:#C5A900; padding:0 8px;' +
            '  letter-spacing:.05em; user-select:none; cursor:default; }' +
            '#l3m-timer-total { font-size:10px; color:#666; padding:0 4px; cursor:default; }' +
            '#l3m-shortcuts-btn { background:transparent; border:1px solid rgba(255,255,255,.25); color:#aaa;' +
            '  font-size:13px; border-radius:4px; padding:3px 8px; cursor:pointer; transition:all .15s; }' +
            '#l3m-shortcuts-btn:hover { border-color:#C5A900; color:#C5A900; }' +
            '#l3m-shortcuts-popup { display:none; position:absolute; top:calc(100% + 6px); right:0; z-index:99999;' +
            '  background:#1a1a1a; border:1px solid #C5A900; border-radius:8px; padding:14px 18px; min-width:220px;' +
            '  box-shadow:0 8px 24px rgba(0,0,0,.4); }' +
            '#l3m-shortcuts-popup.active { display:block; }' +
            '#l3m-shortcuts-popup h4 { margin:0 0 10px; font-family:Montserrat,sans-serif; font-size:12px;' +
            '  text-transform:uppercase; letter-spacing:.08em; color:#C5A900; }' +
            '#l3m-shortcuts-popup table { border-collapse:collapse; width:100%; }' +
            '#l3m-shortcuts-popup td { padding:4px 0; font-size:12px; color:#ccc; }' +
            '#l3m-shortcuts-popup td:first-child { padding-right:16px; }' +
            '#l3m-shortcuts-popup kbd { background:#333; border:1px solid #555; border-radius:3px;' +
            '  padding:1px 6px; font-family:monospace; font-size:11px; color:#fff; }';
        (document.head || document.documentElement).appendChild(s);
    })();

    function getSavedData() {
        try {
            var data = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (data && data.url && (Date.now() - data.ts) < 86400000) return data;
        } catch(e) {}
        return null;
    }
    function getSavedUrl() {
        var d = getSavedData();
        return d ? d.url : null;
    }
    function saveIframeState(url, name) {
        if (isTeacherRole) return;
        try { localStorage.setItem(storageKey, JSON.stringify({url: url, name: name || '', ts: Date.now()})); } catch(e) {}
        hideResumeButton();
    }
    function clearIframeState() {
        try { localStorage.removeItem(storageKey); } catch(e) {}
        hideResumeButton();
    }

    var _resumeObserver = null;
    var _resumeName = '';

    function hideResumeButton() {
        if (_resumeObserver) { _resumeObserver.disconnect(); _resumeObserver = null; }
        var el = document.getElementById('l3m-resume-btn');
        if (el) el.parentNode.removeChild(el);
        _resumeName = '';
    }

    function _insertResumeBtn() {
        if (document.getElementById('l3m-resume-btn')) return;
        var iframeContainer = document.getElementById('l3m-activity-iframe-container');
        if (iframeContainer && iframeContainer.classList.contains('active')) return;
        var toc = document.querySelector('.coursetoc-sectionlist-content');
        if (!toc) return;
        var btn = document.createElement('button');
        btn.id = 'l3m-resume-btn';
        btn.title = 'Reprendre où vous en étiez';
        btn.innerHTML =
            '<span class="l3m-resume-icon">&#9654;</span>' +
            '<span class="l3m-resume-text">' +
            '<span class="l3m-resume-label">Reprendre</span>' +
            '<em>' + _resumeName.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</em>' +
            '</span>';
        btn.addEventListener('click', function() {
            var d = getSavedData();
            if (!d) return;
            hideResumeButton();
            var container = getIframeContainer();
            var iframe = document.getElementById('l3m-activity-iframe');
            iframe.removeAttribute('srcdoc');
            iframe.src = d.url;
            container.classList.add('active');
            var hdr = document.querySelector('.fixed-top') || document.querySelector('header.navbar');
            var off = hdr ? hdr.offsetHeight + 10 : 80;
            window.scrollTo({ top: container.getBoundingClientRect().top + window.pageYOffset - off, behavior: 'smooth' });
        });
        toc.insertBefore(btn, toc.firstChild);
    }

    function showResumeButton(name) {
        hideResumeButton();
        _resumeName = name;
        function _start() {
            _insertResumeBtn();
            // Watch for button being removed by Moodle's JS re-renders
            _resumeObserver = new MutationObserver(function() {
                _insertResumeBtn();
            });
            _resumeObserver.observe(document.body, { childList: true, subtree: true });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', _start);
        } else {
            _start();
        }
    }

    function expandAllCourseSections() {
        document.querySelectorAll('.course-content .sectionbody.collapse').forEach(function(el) {
            el.classList.add('show');
        });
        document.querySelectorAll('.course-content .collapsivetoggle[aria-expanded="false"]').forEach(function(btn) {
            btn.setAttribute('aria-expanded', 'true');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            expandAllCourseSections();
            setTimeout(expandAllCourseSections, 600);
        });
    } else {
        expandAllCourseSections();
        setTimeout(expandAllCourseSections, 600);
    }

    var isTeacherRole = document.body.classList.contains('roleshortname-editingteacher') ||
                        document.body.classList.contains('roleshortname-teacher') ||
                        document.body.classList.contains('roleshortname-manager');

    function cleanIframeContent(iframe) {
        try {
            var src = iframe.src || '';
            // Si formateur : rediriger quiz vers rapport
            if (isTeacherRole && src.indexOf('/mod/quiz/') !== -1 && src.indexOf('/mod/quiz/report.php') === -1) {
                var qm = src.match(/[?&]id=(\d+)/);
                if (qm) {
                    iframe.src = '/mod/quiz/report.php?id=' + qm[1] + '&mode=overview';
                } else {
                    clearIframeState();
                    window.location.href = src;
                }
                return;
            }
            var doc = iframe.contentDocument || iframe.contentWindow.document;
            if (!doc) return;
            var isQuizReport = (iframe.src || '').indexOf('/mod/quiz/report') !== -1;
            var isAttendance = (iframe.src || '').indexOf('/mod/attendance/') !== -1;
            var style = doc.createElement('style');
            style.textContent = [
                '.activity-navigation,', '.course-content-navigation,',
                'a[href*="course/view.php"].btn,', 'a[href*="course/view.php"].btn-icon,',
                '.activity-header .activity-altcontent,',
                '.editmode-switch-form,', '.courseindex,', '.drawer,', '[data-region="fixed-drawer"],',
                'header,', 'header.navbar,', 'nav.navbar,', '.primary-navigation,',
                '#usernavigation,', '.navbar,', '#page-header,', '.page-header-headings,',
                '#page-navbar,', 'footer,', '#page-footer,', '.footer-support-link,',
                '.page-c,', '#page-outer > .page-c,',
                '#theme-main-content > .container-fluid > .row > .sidebar-col,',
                '.sidebar-col,', '[class*="coursetoc"],', '#l3m-activity-iframe-container,',
                '.theme-hide-sidebar,', '.fixed-top,', '.fixed-bar,', 'tr.setallstatusesrow,',
                '.groupselector,', 'select[name="group"],', '#id_group,', '.singleselect,',
                isQuizReport ? 'form.mform,' : '',
                'form.dataformatselector,', 'div.initialbar,', 'div.initialbars,',
                '.quizattemptcounts,',
                '#fitem_id_includeqrcode,', '#fitem_id_rotateqrcode,',
                '#fitem_id_autoassignstatus,', '#fitem_id_allowupdatestatus { display: none !important; }' +
            '/* Rapport quiz : compact */' +
            '.att-table, table.attendancetable { width: 100% !important; }' +
            'table.flexible { font-size: 12px !important; width: 100% !important; table-layout: fixed !important; }' +
            'table.flexible td, table.flexible th { padding: 4px 5px !important; overflow: hidden !important; text-overflow: ellipsis !important; }' +
            'table.flexible th:nth-child(4) { max-width: 120px !important; }' +
                        'table.flexible a { pointer-events: none !important; cursor: default !important; color: inherit !important; text-decoration: none !important; }' +
            'table.flexible td:first-child, table.flexible th:first-child { display: none !important; }' +
            'table.flexible td:nth-child(2), table.flexible th:nth-child(2) { display: none !important; }' +
                        'div[data-container="discussion-tools"] { display:none!important; }' +
            'form#mode, div.singleselect.d-inline-block { display:none!important; }' +
            'a[data-region="post-action"][title="Lien permanent vers ce message"] { display:none!important; }' +
'/* TinyMCE : masquer menubar défaillante, garder la zone de texte */' +
'.tox-menubar { display:none!important; }' +
'.tox-editor-header { min-height:40px !important; }' +
'.tox .tox-toolbar__primary { flex-wrap:wrap !important; }' +
'.tox-tinymce { min-height:120px !important; border:1px solid #ccc !important; border-radius:4px !important; }' +
'/* Formulaire ajout session : amélioration responsive */' +
'.path-mod-attendance .fcontainer, #mform1 .fcontainer { padding: 0 8px !important; }' +
'.path-mod-attendance .form-group, #mform1 .form-group { margin-bottom: 12px !important; }' +
'select, input[type="text"], textarea { max-width: 100% !important; box-sizing: border-box !important; }' +
'/* Page liste sessions émargement */' +
'/* Page prise de présence (take.php) */' +
'#page-footer, .footer-content, footer { display:none!important; }' +
'#lang-menu, .langmenu, a[href*="lang="], form.langmenu { display:none!important; }' +
'.path-mod-attendance #attbody .controls, .path-mod-attendance .urlselect, .path-mod-attendance .singleselect { display:none!important; }' +
'.path-mod-attendance td:first-child, .path-mod-attendance th:first-child { white-space:normal!important; }' +
'.next-page-button, input[name="submitbutton2"] { display:none!important; }'
            ].join('\n');
            doc.head.appendChild(style);
            var links = doc.querySelectorAll('a[href]');
            links.forEach(function(a) {
                var href = a.getAttribute('href') || '';
                var isExternal = (href.startsWith('http') || href.startsWith('//')) && !href.includes('151.80.61.51') && !href.includes('virtualclassroom-dev');
                if (isExternal) {
                    a.setAttribute('target', '_blank');
                    a.setAttribute('rel', 'noopener noreferrer');
                } else {
                    // Forcer les liens internes à rester dans l'iframe
                    a.setAttribute('target', '_self');
                    a.removeAttribute('rel');
                }
            });
            doc.querySelectorAll('form').forEach(function(f) {
                if (f.action && f.action.indexOf('marksessions') !== -1) f.remove();
            });
            var hideIds = [
                'fitem_id_includeqrcode', 'fitem_id_rotateqrcode',
                'fitem_id_autoassignstatus', 'fitem_id_allowupdatestatus'
            ];
            function hideFormFields() {
                hideIds.forEach(function(id) {
                    var el = doc.getElementById(id);
                    if (el) el.style.cssText = 'display:none!important';
                });
                doc.querySelectorAll('tr.setallstatusesrow').forEach(function(el) {
                    el.style.cssText = 'display:none!important';
                });
            }
            hideFormFields();
            setTimeout(hideFormFields, 500);

            // Rendre tous les tableaux de données scrollables horizontalement
            function wrapFlexTables() {
                doc.querySelectorAll('table').forEach(function(tbl) {
                    if (tbl.closest('.l3m-scroll-wrap')) return;
                    if (tbl.rows.length < 2) return; // ignorer tableaux de layout vides
                    if (tbl.closest('.tox, .mce, [id*="tinymce"]')) return; // ignorer TinyMCE
                    var wrap = doc.createElement('div');
                    wrap.className = 'l3m-scroll-wrap';
                    wrap.style.overflowX = 'auto';
                    wrap.style.width = '100%';
                    wrap.style.display = 'block';
                    wrap.style.webkitOverflowScrolling = 'touch';
                    tbl.parentNode.insertBefore(wrap, tbl);
                    wrap.appendChild(tbl);
                });
            }
            wrapFlexTables();
            setTimeout(wrapFlexTables, 500);

            // Intercepter les clics sur les liens de navigation Moodle dans l'iframe
            doc.addEventListener('click', function(ev) {
                var a = ev.target.closest('a[href]');
                if (!a) return;
                var h = a.getAttribute('href') || '';
                var full = h.startsWith('http') ? h : window.location.origin + (h.startsWith('/') ? h : '/' + h);

                // Lien "retour au cours" (course/view.php) → fermer l'iframe proprement
                if (h.indexOf('/course/view.php') !== -1) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    var closeBtn = window.document.getElementById('l3m-iframe-close-btn');
                    if (closeBtn) closeBtn.click();
                    return;
                }
                if (h.indexOf('/mod/quiz/') !== -1) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    window.parent.location.href = full;
                    return;
                }
                // Formulaires émargement (ajouter/modifier/supprimer session) → nouvel onglet
                if (h.indexOf('/mod/attendance/') !== -1 &&
                    (h.indexOf('action=add') !== -1 || h.indexOf('action=edit') !== -1 ||
                     h.indexOf('action=delete') !== -1 || h.indexOf('sessions.php') !== -1)) {
                    ev.preventDefault();
                    ev.stopPropagation();
                    window.open(full, '_blank', 'noopener');
                }
            }, true);
            // Bouton "Ajouter une session" → ouvrir l'URL directement dans nouvel onglet
            if (isAttendance) {
                function hookAddSessionBtn() {
                    doc.querySelectorAll('.addsession, button.addsession, .singlebutton .addsession').forEach(function(btn) {
                        if (btn.dataset.l3mHooked) return;
                        btn.dataset.l3mHooked = '1';
                        var form = btn.closest('form');
                        if (!form) return;
                        var url = form.getAttribute('action') || form.action || '';
                        if (!url) return;
                        btn.addEventListener('click', function(ev) {
                            ev.preventDefault();
                            ev.stopImmediatePropagation();
                            window.open(url, '_blank', 'noopener');
                        }, true);
                    });
                }
                hookAddSessionBtn();
                setTimeout(hookAddSessionBtn, 500);
            }
        // Remove day abbreviations like (mer.) (lun.) from date cells
        function removeDayAbbr() {
            doc.querySelectorAll('td, .cell').forEach(function(td) {
                td.childNodes.forEach(function(node) {
                    if (node.nodeType === 3) {
                        node.textContent = node.textContent.replace(/\s*\([a-zA-Zéèê]{2,4}\.\)\s*/g, ' ').replace(/(\d{1,2})\.(\d{2})\.(\d{2})/g, '$1/$2/$3');
                    }
                });
            });
        }
        removeDayAbbr();
        setTimeout(removeDayAbbr, 1500);
        // Hide attendance tabs except "Ce cours"
        function hideAttTabs() {
            doc.querySelectorAll('.nav-tabs li, .nav li, ul.nav a').forEach(function(el) {
                var t = el.textContent.trim().toLowerCase();
                if (t === 'tous les cours' || t === 'toutes les sessions') {
                    (el.closest('li') || el).style.cssText = 'display:none!important';
                }
            });
        }
        hideAttTabs();
        setTimeout(hideAttTabs, 1500);
        // Hide attendance filter buttons except "Tout"
        function hideAttFilters() {
            doc.querySelectorAll('span.attbtn, span.attcurbtn').forEach(function(span) {
                var t = span.textContent.trim().toLowerCase();
                if (t !== 'tout') {
                    span.style.cssText = 'display:none!important';
                }
            });
        }
        hideAttFilters();
        setTimeout(hideAttFilters, 1500);
        // Hide attendance summary stats block
        function hideAttSummary() {
            doc.querySelectorAll('*').forEach(function(el) {
                if (el.children.length > 0) return;
                var t = el.textContent.trim().toLowerCase();
                if (t.indexOf('nombre total de sessions') !== -1 ||
                    t.indexOf('points de toutes') !== -1 ||
                    t.indexOf('pourcentage de toutes') !== -1 ||
                    t.indexOf('point maximum possible') !== -1 ||
                    t.indexOf('pourcentage maximum possible') !== -1) {
                    var container = el.closest('table, .generalbox, .box, tr') || el.parentElement;
                    if (container && container.tagName === 'TR') container = container.closest('table') || container;
                    if (container) container.style.cssText = 'display:none!important';
                }
            });
        }
        hideAttSummary();
        setTimeout(hideAttSummary, 1500);
        // Hide forum extras: Réglages, display mode dropdown, Permalien
        function hideForumExtras() {
            ['div[data-container="discussion-tools"]',
             'form#mode', 'div.singleselect.d-inline-block',
             'a[data-region="post-action"][title="Lien permanent vers ce message"]'
            ].forEach(function(sel) {
                doc.querySelectorAll(sel).forEach(function(el) {
                    el.style.cssText = 'display:none!important';
                });
            });
        }
        hideForumExtras();
        setTimeout(hideForumExtras, 1500);
        // MutationObserver inside iframe to catch dynamically rendered elements
        try {
            var iframeObs = new doc.defaultView.MutationObserver(function() {
                hideForumExtras();
                hideAttTabs();
                hideAttFilters();
                hideAttSummary();
                removeDayAbbr();
            });
            iframeObs.observe(doc.body, {childList: true, subtree: true});
        } catch(e2) {}
        } catch(e) {}
    }

    function getIframeContainer() {
        var container = document.getElementById('l3m-activity-iframe-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'l3m-activity-iframe-container';
            container.innerHTML =
                '<div id="l3m-iframe-toolbar" style="position:relative;">' +
                '<button id="l3m-notes-btn" title="Mes notes — Raccourci : N">📝 Notes <small style="opacity:.6;font-size:10px;">[N]</small></button>' +
                (!isTeacherRole ? '<span id="l3m-timer-display" title="Temps de la session">⏱ 00:00</span>' : '') +
                '<button id="l3m-shortcuts-btn" title="Raccourcis clavier">⌨</button>' +
                '<div id="l3m-shortcuts-popup">' +
                '<h4>Raccourcis clavier</h4>' +
                '<table><tbody>' +
                '<tr><td><kbd>N</kbd></td><td>Ouvrir / fermer les notes</td></tr>' +
                '<tr><td><kbd>F</kbd></td><td>Plein écran</td></tr>' +
                '<tr><td><kbd>Échap</kbd></td><td>Fermer l\'activité</td></tr>' +
                '</tbody></table>' +
                (!isTeacherRole ? '<div style="margin-top:10px;padding-top:8px;border-top:1px solid #333;font-size:11px;color:#888;">Temps total sur ce cours : <span id="l3m-timer-total">—</span></div>' : '') +
                '</div>' +
                '<button id="l3m-iframe-close-btn" title="Fermer — Raccourci : Échap">&times;</button>' +
                '</div>' +
                '<iframe id="l3m-activity-iframe" src="" allowfullscreen></iframe>';
            var pageContent = document.getElementById('page-content');
            if (pageContent) {
                pageContent.insertBefore(container, pageContent.firstChild);
            } else {
                document.body.appendChild(container);
            }
            var iframe = container.querySelector('#l3m-activity-iframe');
            iframe.addEventListener('load', function() {
                iframe.style.opacity = '0';
                cleanIframeContent(iframe);
                setTimeout(function(){ iframe.style.opacity = '1'; }, 150);
                // Register completion for current activity via our endpoint (students only)
                if (!isTeacherRole) {
                    var src = iframe.src || '';
                    var cmMatch = src.match(/[?&]id=(\d+)/);
                    if (cmMatch && src.indexOf('/mod/') !== -1 && src.indexOf('/mod/quiz/') === -1) {
                        var cmid = cmMatch[1];
                        fetch('/theme/mb2nl/l3m_complete.php?cmid=' + cmid, {credentials: 'same-origin'})
                            .catch(function() {});
                    }
                }
            });
            document.getElementById('l3m-iframe-close-btn').addEventListener('click', function() {
                container.classList.remove('active');
                iframe.removeAttribute('srcdoc');
                iframe.src = '';
                clearIframeState();
                hideNotesPanel();
                var wasActive = !!_currentActivityId;
                _currentActivityId = null;
                resetSessionTimer();
                // Flag: an activity was viewed this session → trigger celebration on reload (students only)
                if (!isTeacherRole && wasActive && courseId && courseId !== '0') {
                    try { localStorage.setItem('l3m_celeb_' + courseId, '1'); } catch(e) {}
                }
                setTimeout(function() { window.location.reload(); }, 300);
            });
            document.getElementById('l3m-shortcuts-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                var popup = document.getElementById('l3m-shortcuts-popup');
                popup.classList.toggle('active');
                // Update total time display
                var totalEl = document.getElementById('l3m-timer-total');
                if (totalEl) totalEl.textContent = _fmtTime(_getTotalSeconds() + _sessionSeconds) || '—';
            });
            document.addEventListener('click', function() {
                var popup = document.getElementById('l3m-shortcuts-popup');
                if (popup) popup.classList.remove('active');
            });
            document.getElementById('l3m-notes-btn').addEventListener('click', function() {
                var panel = document.getElementById('l3m-notes-panel');
                if (panel && panel.classList.contains('active')) {
                    hideNotesPanel();
                    this.classList.remove('active');
                } else {
                    if (_currentActivityId) {
                        showNotesPanel(_currentActivityId, _currentActivityName);
                        this.classList.add('active');
                    }
                }
            });
        }
        return container;
    }

    function restoreIframeState() {
        if (isTeacherRole) return;
        var data = getSavedData();
        if (!data) return;
        if (data.url.indexOf('/mod/quiz/') !== -1) { clearIframeState(); return; }
        // On page load: only show the button, don't auto-open the iframe
        if (data.name) showResumeButton(data.name);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreIframeState);
    } else {
        restoreIframeState();
    }

    function scrollToSection(target) {
        var btn = target.querySelector('[aria-expanded="false"]');
        if (btn) btn.click();
        var hdr = document.querySelector('.fixed-top') || document.querySelector('header.navbar');
        var off = hdr ? hdr.offsetHeight + 20 : 90;
        window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - off, behavior: 'smooth' });
    }

    function isExternalUrl(href) {
        try {
            var url = new URL(href, window.location.origin);
            return url.hostname !== window.location.hostname;
        } catch(e) { return false; }
    }

    function getActivityName(link) {
        var nameEl = link.querySelector('.itemname, .instancename, .activity-instance-name');
        if (nameEl) return nameEl.textContent.trim();
        return link.textContent.trim().replace(/\s+/g, ' ').split('\n')[0].trim().substring(0, 60);
    }

    document.addEventListener('click', function(e) {
        var link = e.target.closest('.coursetoc-sectionlist-content a');
        if (!link) return;
        var href = link.getAttribute('href') || '';
        var m1 = href.match(/section\.php\?id=(\d+)/);
        if (m1) {
            var target = document.querySelector('li[data-id="' + m1[1] + '"][data-for="section"]')
                      || document.querySelector('li[data-id="' + m1[1] + '"]');
            if (target) {
                e.preventDefault();
                scrollToSection(target);
                document.querySelectorAll('.coursetoc-sectionlist-content .coursetoc-section')
                    .forEach(function(s) { s.classList.remove('active'); });
                var ps = link.closest('.coursetoc-section');
                if (ps) ps.classList.add('active');
            }
            return;
        }
        if (href.indexOf('/mod/') !== -1) {
            if (isExternalUrl(href) || href.indexOf('/mod/url/') !== -1) {
                e.preventDefault();
                window.open(href, '_blank', 'noopener,noreferrer');
                return;
            }
            // Quiz : formateur → rapport ; étudiant → page complète
            if (href.indexOf('/mod/quiz/') !== -1) {
                e.preventDefault();
                if (!isTeacherRole) {
                    clearIframeState();
                    window.location.href = href;
                    return;
                }
                var quizId = href.match(/[?&]id=(\d+)/);
                if (quizId) {
                    var reportUrl = '/mod/quiz/report.php?id=' + quizId[1] + '&mode=overview';
                    var container = getIframeContainer();
                    var iframeEl = document.getElementById('l3m-activity-iframe');
                    container.classList.add('active');
                    saveIframeState(reportUrl, getActivityName(link));
                    var hdr = document.querySelector('.fixed-top') || document.querySelector('header.navbar');
                    var off = hdr ? hdr.offsetHeight + 10 : 80;
                    window.scrollTo({top: container.getBoundingClientRect().top + window.pageYOffset - off, behavior: 'smooth'});
                    // Fetch et extraction du contenu utile uniquement
                    fetch(reportUrl, {credentials: 'same-origin'})
                        .then(function(r){ return r.text(); })
                        .then(function(html){
                            var parser = new DOMParser();
                            var doc = parser.parseFromString(html, 'text/html');
                            // Extraire uniquement le tableau des tentatives
                            var table = doc.querySelector('table.attempts, table.generaltable, #mod-quiz-report table');
                            var avgRow = doc.querySelector('tr.lastrow, tr.avg');
                            var content = table ? table.outerHTML : '<p>Aucune tentative.</p>';
                            var srcdoc = '<html><head><meta charset="utf-8">' +
                                '<style>body{font-family:sans-serif;font-size:14px;padding:16px;margin:0;}' +
                                'table{border-collapse:collapse;width:100%;}' +
                                'th,td{border:1px solid #ddd;padding:6px 10px;text-align:left;}' +
                                'th{background:#f5f5f5;font-weight:600;}' +
                                'tr:nth-child(even){background:#fafafa;}' +
                                'a{color:#C9A227;}</style>' +
                                '</head><body>' + content + '</body></html>';
                            iframeEl.srcdoc = srcdoc;
                        })
                        .catch(function(){
                            iframeEl.src = reportUrl;
                        });
                }
                return;
            }
            e.preventDefault();
            var actName = getActivityName(link);
            var container = getIframeContainer();
            var iframe = document.getElementById('l3m-activity-iframe');
            iframe.removeAttribute('srcdoc');
            iframe.src = href;
            container.classList.add('active');
            saveIframeState(href, actName);
            _currentActivityId = getActivityIdFromUrl(href);
            if (!isTeacherRole) { resetSessionTimer(); startTimer(); }
            _currentActivityName = actName;
            // Update notes panel if open
            var notesPanel = document.getElementById('l3m-notes-panel');
            if (notesPanel && notesPanel.classList.contains('active')) {
                showNotesPanel(_currentActivityId, actName);
            }
            var hdr = document.querySelector('.fixed-top') || document.querySelector('header.navbar');
            var off = hdr ? hdr.offsetHeight + 10 : 80;
            window.scrollTo({ top: container.getBoundingClientRect().top + window.pageYOffset - off, behavior: 'smooth' });
            document.querySelectorAll('.coursetoc-sectionlist-content .module-item')
                .forEach(function(m) { m.classList.remove('active'); });
            var pm = link.closest('.module-item');
            if (pm) pm.classList.add('active');
        }
    }, true);

    // ---- CÉLÉBRATION DE PROGRESSION ----
    (function initCelebration() {
        if (!courseId || courseId === '0') return;
        if (isTeacherRole) return;
        var _celebKey = 'l3m_celeb_' + courseId;
        var _progKey  = 'l3m_prog_'  + courseId;

        function showConfetti(full) {
            var canvas = document.createElement('canvas');
            canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:999998;pointer-events:none;';
            document.body.appendChild(canvas);
            canvas.width = window.innerWidth;
            canvas.height = window.innerHeight;
            var ctx = canvas.getContext('2d');
            var colors = ['#C5A900','#e8c800','#ffffff','#1a1a1a','#f0d060','#fff5cc'];
            var count = full ? 180 : 80;
            var particles = [];
            for (var i = 0; i < count; i++) {
                particles.push({
                    x: full ? Math.random()*canvas.width : canvas.width/2 + (Math.random()-0.5)*300,
                    y: full ? -20 : canvas.height * 0.3,
                    w: Math.random()*12+4, h: Math.random()*7+3,
                    color: colors[Math.floor(Math.random()*colors.length)],
                    vx: (Math.random()-0.5)*(full?6:4),
                    vy: Math.random()*3+1,
                    angle: Math.random()*Math.PI*2,
                    va: (Math.random()-0.5)*0.15
                });
            }
            var maxFrames = full ? 220 : 120;
            var frame = 0;
            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                particles.forEach(function(p) {
                    p.x += p.vx; p.y += p.vy; p.vy += 0.12; p.angle += p.va;
                    ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.angle);
                    ctx.globalAlpha = Math.max(0, 1 - frame/maxFrames);
                    ctx.fillStyle = p.color;
                    ctx.fillRect(-p.w/2, -p.h/2, p.w, p.h);
                    ctx.restore();
                });
                if (++frame < maxFrames) requestAnimationFrame(animate);
                else if (canvas.parentNode) canvas.parentNode.removeChild(canvas);
            }
            requestAnimationFrame(animate);
        }

        function showToast(msg, icon) {
            var t = document.createElement('div');
            t.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%) translateY(20px);' +
                'background:#1a1a1a;color:#fff;padding:12px 24px;border-radius:30px;' +
                'border:2px solid #C5A900;font-family:Montserrat,sans-serif;font-weight:700;font-size:14px;' +
                'z-index:999999;box-shadow:0 4px 20px rgba(0,0,0,.4);transition:all .4s;opacity:0;white-space:nowrap;';
            t.textContent = icon + '  ' + msg;
            document.body.appendChild(t);
            setTimeout(function(){ t.style.opacity='1'; t.style.transform='translateX(-50%) translateY(0)'; }, 50);
            setTimeout(function(){
                t.style.opacity='0'; t.style.transform='translateX(-50%) translateY(20px)';
                setTimeout(function(){ if(t.parentNode) t.parentNode.removeChild(t); }, 400);
            }, 3500);
        }

        function readProgress() {
            var el = document.querySelector('.fsmod-progress-progress');
            if (el && el.style.width) { var w = parseFloat(el.style.width); if (!isNaN(w) && w >= 0) return w; }
            el = document.querySelector('.theme-course-progress .progress-value');
            if (el) { var v = parseFloat(el.textContent); if (!isNaN(v) && v >= 0) return v; }
            el = document.querySelector('[role="progressbar"][aria-valuenow]');
            if (el) { var av = parseFloat(el.getAttribute('aria-valuenow')); if (!isNaN(av) && av >= 0) return av; }
            el = document.querySelector('.progress-bar[style*="width"]');
            if (el) { var bw = parseFloat(el.style.width); if (!isNaN(bw) && bw >= 0) return bw; }
            return -1;
        }

        function celebrate() {
            try { localStorage.removeItem(_celebKey); } catch(e) {}
            var p = readProgress();
            var full = (p >= 100);
            var milestones = [25, 50, 75];
            var prev = parseFloat(localStorage.getItem(_progKey) || '0');
            var crossed = full || milestones.some(function(m){ return prev < m && p >= m; });
            if (p >= 0) { try { localStorage.setItem(_progKey, String(p)); } catch(e) {} }
            setTimeout(function() {
                showConfetti(crossed || full);
                if (full) {
                    showToast('Formation terminée ! Félicitations !', '🏆');
                } else if (p > 0) {
                    showToast(Math.round(p) + '% de la formation terminé — continuez !', '🎉');
                } else {
                    showToast('Activité terminée — continuez !', '🎉');
                }
            }, 700);
        }

        // On page load: if flag set by iframe close → celebrate
        function init() {
            try {
                if (localStorage.getItem(_celebKey) === '1') {
                    celebrate();
                } else {
                    // Update stored progress baseline (no celebration)
                    var p = readProgress();
                    if (p >= 0) { try { localStorage.setItem(_progKey, String(p)); } catch(e) {} }
                }
            } catch(e) {}
        }

        // Test functions accessible from browser console
        window.l3mTestCelebration = function() { showConfetti(false); showToast('Activité terminée — continuez !', '🎉'); };
        window.l3mDebugProgress = function() {
            var p = readProgress();
            var stored = localStorage.getItem(_progKey);
            var flag = localStorage.getItem(_celebKey);
            console.log('[L3M Célébration] Clé célébration:', _celebKey, '=', flag);
            console.log('[L3M Célébration] Progression actuelle:', p, '| Stockée:', stored);
            return {flag: flag, current: p, stored: stored};
        };
        window.l3mResetProgress = function() {
            try { localStorage.removeItem(_progKey); localStorage.removeItem(_celebKey); } catch(e) {}
            console.log('[L3M] Réinitialisé. Rechargez et ouvrez/fermez une activité.');
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() { setTimeout(init, 500); });
        } else {
            setTimeout(init, 500);
        }
    })();

    // ---- RACCOURCIS CLAVIER ----
    document.addEventListener('keydown', function(e) {
        // Ignorer si l'utilisateur tape dans un champ texte
        var tag = (e.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable) return;

        var iframeContainer = document.getElementById('l3m-activity-iframe-container');
        var iframeActive = iframeContainer && iframeContainer.classList.contains('active');

        // N → ouvrir/fermer le panneau Notes
        if (e.key === 'n' || e.key === 'N') {
            if (!iframeActive) return;
            var notesBtn = document.getElementById('l3m-notes-btn');
            if (notesBtn) notesBtn.click();
            return;
        }

        // F → plein écran de l'iframe
        if (e.key === 'f' || e.key === 'F') {
            if (!iframeActive) return;
            var iframe = document.getElementById('l3m-activity-iframe');
            if (!iframe) return;
            if (!document.fullscreenElement) {
                iframe.requestFullscreen && iframe.requestFullscreen();
            } else {
                document.exitFullscreen && document.exitFullscreen();
            }
            return;
        }

        // Échap → fermer l'iframe
        if (e.key === 'Escape') {
            if (!iframeActive) return;
            // Ne pas interférer si une modale est ouverte
            if (document.querySelector('.modal.show, .modal[style*="display: block"]')) return;
            var closeBtn = document.getElementById('l3m-iframe-close-btn');
            if (closeBtn) closeBtn.click();
        }
    });
})();