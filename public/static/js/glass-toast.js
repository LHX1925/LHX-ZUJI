/**
 * 液态玻璃主题 - 全局消息提示组件 v1.0
 * 统一替代原生 alert / 旧式弹窗，提供美观的玻璃质感 Toast 通知。
 *
 * 用法：
 *   GlassToast.success('操作成功');
 *   GlassToast.success('签到成功，获得 18 积分', 2600);
 *   GlassToast.error('操作失败，请重试');
 *   GlassToast.warning('请先完成实名认证');
 *   GlassToast.info('已复制到剪贴板');
 *   GlassToast.show({ type:'success', title:'签到成功', msg:'获得 18 积分' });
 *   GlassToast.confirm({ title, msg, confirmText, cancelText, onOk, onCancel });
 *   GlassToast.alert({ title, msg, icon, onClose });
 *
 * type 支持: success / error / warning / info / loading
 * 所有样式使用 CSS 变量以适配浅色/深色玻璃主题。
 */
(function(global) {
    'use strict';

    var doc = document;
    var CONTAINER_ID = 'glass-toast-container';
    var zIndexBase = 99999;

    // ---------- 注入样式 ----------
    function ensureStyle() {
        if (doc.getElementById('glass-toast-style')) return;
        var css = [
            '#glass-toast-container{position:fixed;top:20px;left:50%;transform:translateX(-50%);z-index:99999;display:flex;flex-direction:column;align-items:center;gap:10px;pointer-events:none;width:100%;padding:0 16px;box-sizing:border-box;}',
            '.glass-toast{display:flex;align-items:flex-start;gap:12px;min-width:240px;max-width:420px;width:auto;padding:14px 18px;border-radius:14px;background:var(--glass-card-bg, rgba(255,255,255,0.96));backdrop-filter:blur(18px) saturate(160%);-webkit-backdrop-filter:blur(18px) saturate(160%);border:1px solid var(--glass-border, rgba(226,232,240,0.7));box-shadow:0 12px 40px rgba(15,23,42,0.14), 0 2px 10px rgba(15,23,42,0.06);pointer-events:auto;opacity:0;transform:translateY(-14px) scale(0.96);animation:glassToastIn .28s cubic-bezier(.21,1.02,.73,1) forwards;font-family:inherit;}',
            '.glass-toast.leaving{animation:glassToastOut .26s ease forwards;}',
            '@keyframes glassToastIn{to{opacity:1;transform:translateY(0) scale(1);}}',
            '@keyframes glassToastOut{to{opacity:0;transform:translateY(-10px) scale(0.97);}}',
            '.glass-toast .gt-ico{width:38px;height:38px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;position:relative;}',
            '.glass-toast .gt-ico::after{content:"";position:absolute;inset:0;border-radius:50%;box-shadow:inset 0 0 0 1px rgba(255,255,255,0.25);}',
            '.glass-toast .gt-body{flex:1;min-width:0;}',
            '.glass-toast .gt-title{font-size:14.5px;font-weight:700;color:var(--glass-text, #1e293b);line-height:1.35;margin:0;}',
            '.glass-toast .gt-msg{font-size:13px;color:var(--glass-text-secondary, #64748b);line-height:1.5;margin-top:2px;word-break:break-word;}',
            '.glass-toast .gt-title:only-child{margin:0;}',
            '.glass-toast .gt-ico.success{background:linear-gradient(135deg,#22c55e,#16a34a);}',
            '.glass-toast .gt-ico.error{background:linear-gradient(135deg,#ef4444,#dc2626);}',
            '.glass-toast .gt-ico.warning{background:linear-gradient(135deg,#f59e0b,#d97706);}',
            '.glass-toast .gt-ico.info{background:linear-gradient(135deg,#3b82f6,#2563eb);}',
            '.glass-toast .gt-ico.loading{background:linear-gradient(135deg,#6366f1,#4f46e5);}',
            '.glass-toast .gt-ico i{font-style:normal;font-weight:700;}',
            '.glass-toast .gt-ico .gt-spin{display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,0.4);border-top-color:#fff;border-radius:50%;animation:gtSpin .8s linear infinite;}',
            '@keyframes gtSpin{to{transform:rotate(360deg);}}',
            '.glass-toast .gt-close{margin-left:4px;color:var(--glass-text-muted, #94a3b8);font-size:16px;line-height:1;cursor:pointer;background:none;border:none;padding:0 2px;flex-shrink:0;}',
            '.glass-toast .gt-close:hover{color:var(--glass-text, #1e293b);}',
            /* 深色主题适配 */
            'body[data-theme="dark"] #glass-toast-container .glass-toast, .glass-dark #glass-toast-container .glass-toast{background:rgba(30,41,59,0.92);border-color:rgba(148,163,184,0.2);}',
            'body[data-theme="dark"] #glass-toast-container .gt-title, .glass-dark #glass-toast-container .gt-title{color:#f1f5f9;}',
            'body[data-theme="dark"] #glass-toast-container .gt-msg, .glass-dark #glass-toast-container .gt-msg{color:#cbd5e1;}',
            '@media (max-width:768px){.glass-toast{min-width:200px;max-width:92vw;padding:12px 14px;border-radius:12px;}}'
        ].join('');
        var style = doc.createElement('style');
        style.id = 'glass-toast-style';
        style.textContent = css;
        doc.head.appendChild(style);
    }

    function getContainer() {
        var c = doc.getElementById(CONTAINER_ID);
        if (!c) {
            c = doc.createElement('div');
            c.id = CONTAINER_ID;
            doc.body.appendChild(c);
        }
        return c;
    }

    var ICONS = {
        success: '✓',
        error: '✕',
        warning: '!',
        info: 'i',
        loading: ''
    };

    function makeIcon(type) {
        var ico = doc.createElement('div');
        ico.className = 'gt-ico ' + type;
        if (type === 'loading') {
            var spin = doc.createElement('span');
            spin.className = 'gt-spin';
            ico.appendChild(spin);
        } else {
            var i = doc.createElement('i');
            i.textContent = ICONS[type] || 'i';
            ico.appendChild(i);
        }
        return ico;
    }

    /**
     * 创建一条 toast。
     * @param {object} opt {type, title, msg, duration, closable}
     * @returns {HTMLElement} 返回 DOM，便于 loading 手动关闭
     */
    function createToast(opt) {
        opt = opt || {};
        var type = opt.type || 'info';
        var title = opt.title || '';
        var msg = opt.msg || '';
        var duration = (typeof opt.duration === 'number') ? opt.duration : 2400;
        var closable = !!opt.closable;

        ensureStyle();
        var el = doc.createElement('div');
        el.className = 'glass-toast';
        el.appendChild(makeIcon(type));

        var body = doc.createElement('div');
        body.className = 'gt-body';
        if (title) {
            var t = doc.createElement('div');
            t.className = 'gt-title';
            t.textContent = title;
            body.appendChild(t);
        }
        if (msg) {
            var m = doc.createElement('div');
            m.className = 'gt-msg';
            m.textContent = msg;
            body.appendChild(m);
        }
        el.appendChild(body);

        var closeBtn;
        if (closable || duration <= 0) {
            closeBtn = doc.createElement('button');
            closeBtn.className = 'gt-close';
            closeBtn.type = 'button';
            closeBtn.innerHTML = '&times;';
            closeBtn.setAttribute('aria-label', '关闭');
            closeBtn.addEventListener('click', function() { dismiss(el, true); });
            el.appendChild(closeBtn);
        }

        getContainer().appendChild(el);

        var timer = null;
        if (duration > 0) {
            timer = setTimeout(function() { dismiss(el, false); }, duration);
        }
        el._gtTimer = timer;
        return el;
    }

    function dismiss(el, immediate) {
        if (!el || el._gtRemoving) return;
        el._gtRemoving = true;
        if (el._gtTimer) { clearTimeout(el._gtTimer); el._gtTimer = null; }
        if (immediate) {
            if (el.parentNode) el.parentNode.removeChild(el);
            return;
        }
        el.classList.add('leaving');
        setTimeout(function() {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, 280);
    }

    // ---------- 遮罩弹层（alert / confirm） ----------
    function ensureOverlayStyle() {
        if (doc.getElementById('glass-toast-overlay-style')) return;
        var css = [
            '.glass-modal-mask{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,0.35);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);display:flex;align-items:center;justify-content:center;padding:20px;animation:gtMaskIn .2s ease;}',
            '@keyframes gtMaskIn{from{opacity:0;}}',
            '.glass-modal-mask.closing{animation:gtMaskOut .2s ease forwards;}',
            '@keyframes gtMaskOut{to{opacity:0;}}',
            '.glass-modal-box{width:100%;max-width:360px;background:var(--glass-card-bg, rgba(255,255,255,0.98));backdrop-filter:blur(20px) saturate(160%);-webkit-backdrop-filter:blur(20px) saturate(160%);border:1px solid var(--glass-border, rgba(226,232,240,0.7));border-radius:18px;box-shadow:0 24px 70px rgba(15,23,42,0.25);overflow:hidden;animation:gtBoxIn .26s cubic-bezier(.21,1.02,.73,1);}',
            '@keyframes gtBoxIn{from{opacity:0;transform:translateY(12px) scale(0.96);}to{opacity:1;transform:translateY(0) scale(1);}}',
            '.glass-modal-box .gm-head{padding:22px 24px 4px;text-align:center;}',
            '.glass-modal-box .gm-ico{width:56px;height:56px;border-radius:50%;margin:0 auto;display:flex;align-items:center;justify-content:center;font-size:26px;color:#fff;font-weight:700;}',
            '.glass-modal-box .gm-ico.success{background:linear-gradient(135deg,#22c55e,#16a34a);}',
            '.glass-modal-box .gm-ico.error{background:linear-gradient(135deg,#ef4444,#dc2626);}',
            '.glass-modal-box .gm-ico.warning{background:linear-gradient(135deg,#f59e0b,#d97706);}',
            '.glass-modal-box .gm-ico.info{background:linear-gradient(135deg,#3b82f6,#2563eb);}',
            '.glass-modal-box .gm-title{margin-top:14px;font-size:17px;font-weight:800;color:var(--glass-text, #1e293b);}',
            '.glass-modal-box .gm-msg{margin-top:8px;font-size:14px;color:var(--glass-text-secondary, #64748b);line-height:1.6;word-break:break-word;}',
            '.glass-modal-box .gm-btns{display:flex;gap:10px;padding:20px 24px 22px;}',
            '.glass-modal-box .gm-btns.single{justify-content:center;}',
            '.glass-modal-box .gm-btn{flex:1;height:40px;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;transition:all .2s ease;}',
            '.glass-modal-box .gm-btn.cancel{background:var(--glass-muted-bg, #f1f5f9);color:var(--glass-text-secondary, #475569);}',
            '.glass-modal-box .gm-btn.cancel:hover{background:#e2e8f0;}',
            '.glass-modal-box .gm-btn.ok{background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;}',
            '.glass-modal-box .gm-btn.ok:hover{transform:translateY(-1px);box-shadow:0 6px 16px rgba(99,102,241,0.4);}',
            '.glass-modal-box .gm-btn.ok.danger{background:linear-gradient(135deg,#ef4444,#dc2626);box-shadow:none;}',
            '.glass-modal-box .gm-btn.ok.danger:hover{box-shadow:0 6px 16px rgba(239,68,68,0.4);}',
            'body[data-theme="dark"] .glass-modal-box, .glass-dark .glass-modal-box{background:rgba(30,41,59,0.96);border-color:rgba(148,163,184,0.2);}',
            'body[data-theme="dark"] .gm-title, .glass-dark .gm-title{color:#f1f5f9;}',
            'body[data-theme="dark"] .gm-msg, .glass-dark .gm-msg{color:#cbd5e1;}'
        ].join('');
        var style = doc.createElement('style');
        style.id = 'glass-toast-overlay-style';
        style.textContent = css;
        doc.head.appendChild(style);
    }

    function openModal(opt) {
        opt = opt || {};
        ensureOverlayStyle();
        var type = opt.type || 'info';
        var mask = doc.createElement('div');
        mask.className = 'glass-modal-mask';

        var box = doc.createElement('div');
        box.className = 'glass-modal-box';

        if (opt.icon !== false) {
            var head = doc.createElement('div');
            head.className = 'gm-head';
            var ico = doc.createElement('div');
            ico.className = 'gm-ico ' + type;
            ico.textContent = ICONS[type] || 'i';
            head.appendChild(ico);
            if (opt.title) {
                var title = doc.createElement('div');
                title.className = 'gm-title';
                title.textContent = opt.title;
                head.appendChild(title);
            }
            box.appendChild(head);
        }
        if (opt.msg) {
            var msg = doc.createElement('div');
            msg.className = 'gm-msg';
            if (opt.msgHtml) {
                msg.innerHTML = opt.msg;
            } else {
                msg.textContent = opt.msg;
            }
            box.appendChild(msg);
        }

        function close(result) {
            if (mask._closed) return;
            mask._closed = true;
            mask.classList.add('closing');
            setTimeout(function() {
                if (mask.parentNode) mask.parentNode.removeChild(mask);
            }, 200);
            if (result === true && typeof opt.onOk === 'function') opt.onOk();
            if (result === false && typeof opt.onCancel === 'function') opt.onCancel();
            if (typeof opt.onClose === 'function' && result !== true) opt.onClose();
        }

        var btns = doc.createElement('div');
        if (opt.confirm) {
            btns.className = 'gm-btns';
            var cancelBtn = doc.createElement('button');
            cancelBtn.className = 'gm-btn cancel';
            cancelBtn.type = 'button';
            cancelBtn.textContent = opt.cancelText || '取消';
            cancelBtn.addEventListener('click', function() { close(false); });
            btns.appendChild(cancelBtn);

            var okBtn = doc.createElement('button');
            okBtn.className = 'gm-btn ok' + (opt.danger ? ' danger' : '');
            okBtn.type = 'button';
            okBtn.textContent = opt.confirmText || '确定';
            okBtn.addEventListener('click', function() { close(true); });
            btns.appendChild(okBtn);
        } else {
            btns.className = 'gm-btns single';
            var onlyBtn = doc.createElement('button');
            onlyBtn.className = 'gm-btn ok';
            onlyBtn.type = 'button';
            onlyBtn.textContent = opt.okText || '我知道了';
            onlyBtn.addEventListener('click', function() { close(true); });
            btns.appendChild(onlyBtn);
        }
        box.appendChild(btns);

        mask.appendChild(box);
        doc.body.appendChild(mask);

        if (opt.maskClose !== false) {
            mask.addEventListener('click', function(e) {
                if (e.target === mask) close(false);
            });
        }
        return { el: mask, close: close };
    }

    // ---------- 对外 API ----------
    var api = {
        show: function(opt) { return createToast(opt); },

        success: function(msg, duration, title) {
            var o = { type: 'success', duration: duration };
            if (typeof msg === 'string') {
                o.title = title || msg;
                o.msg = title ? msg : '';
            }
            return createToast(o);
        },

        error: function(msg, duration, title) {
            var o = { type: 'error', duration: duration };
            if (typeof msg === 'string') {
                o.title = title || msg;
                o.msg = title ? msg : '';
            }
            return createToast(o);
        },

        warning: function(msg, duration, title) {
            var o = { type: 'warning', duration: duration };
            if (typeof msg === 'string') {
                o.title = title || msg;
                o.msg = title ? msg : '';
            }
            return createToast(o);
        },

        info: function(msg, duration, title) {
            var o = { type: 'info', duration: duration };
            if (typeof msg === 'string') {
                o.title = title || msg;
                o.msg = title ? msg : '';
            }
            return createToast(o);
        },

        loading: function(msg, title) {
            return createToast({ type: 'loading', title: title || msg, msg: title ? msg : '', duration: 0, closable: false });
        },

        close: function(el) { dismiss(el, true); },

        alert: function(opt) {
            if (typeof opt === 'string') { opt = { msg: opt, type: 'info' }; }
            opt = opt || {};
            return openModal(opt);
        },

        confirm: function(opt) {
            if (typeof opt === 'string') { opt = { msg: opt }; }
            opt = opt || {};
            opt.confirm = true;
            return openModal(opt);
        }
    };

    global.GlassToast = api;
    // 兼容别名
    global.gToast = api;
})(window);
