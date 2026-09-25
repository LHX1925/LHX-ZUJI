/* ============================================================
   layer-modern.js — 现代风格弹窗组件（layer 全量兼容替身）
   覆盖后台 / 前台所有 layer.alert / confirm / msg / open /
   load / prompt 调用，统一为企业蓝白现代风格，支持深色主题。
   兼容点：
   - 保留 layui-layer / layui-layer-content 类名（旧 success 钩子可用）
   - content 传 jQuery 对象时，关闭后归还原节点（可重复打开）
   - layer.msg 第三参 end 回调、layer.load 的 time:false 常驻语义
   ============================================================ */
(function () {
    if (window.layer && window.layer.__modern) return;

    var zIndex = 200000;
    var counter = 1;
    var inst = {}; // idx -> {root, shade, end, restore, kind}

    /* ---------- 样式 ---------- */
    var css = ''
        + '.lm-shade{position:fixed;inset:0;background:rgba(15,23,42,.45);animation:lmFadeIn .18s ease both;}'
        + '.lm-dialog{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;border-radius:16px;'
        + 'box-shadow:0 24px 64px rgba(15,23,42,.24),0 4px 16px rgba(15,23,42,.08);max-width:calc(100vw - 32px);'
        + 'font-family:inherit;animation:lmPop .22s cubic-bezier(.21,1.02,.73,1) both;overflow:hidden;}'
        + '.lm-title{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;'
        + 'border-bottom:1px solid #eef2f7;font-size:15px;font-weight:600;color:#1e293b;}'
        + '.lm-title span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
        + '.lm-x{width:30px;height:30px;border:0;background:transparent;border-radius:8px;cursor:pointer;flex:0 0 auto;'
        + 'display:flex;align-items:center;justify-content:center;color:#8b96a8;transition:.15s;}'
        + '.lm-x:hover{background:#f1f5fb;color:#1f2937;}'
        + '.lm-x svg{width:16px;height:16px;}'
        + '.lm-body{font-size:14px;color:#334155;line-height:1.65;overflow:auto;}'
        + '.lm-body::-webkit-scrollbar{width:6px}.lm-body::-webkit-scrollbar-thumb{background:#dbe2ec;border-radius:3px}'
        + '.lm-center{padding:26px 28px 6px;text-align:center;}'
        + '.lm-ico{width:54px;height:54px;border-radius:50%;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;}'
        + '.lm-ico svg{width:28px;height:28px;}'
        + '.lm-ico.ok{background:#ecfdf5;color:#16a34a;}'
        + '.lm-ico.err{background:#fef2f2;color:#dc2626;}'
        + '.lm-ico.ask{background:#fffbeb;color:#d97706;}'
        + '.lm-ico.info{background:#eff6ff;color:#2563eb;}'
        + '.lm-text{word-break:break-word;font-size:14.5px;}'
        + '.lm-btns{display:flex;gap:10px;justify-content:center;padding:18px 24px 22px;}'
        + '.lm-btn{height:38px;padding:0 28px;border-radius:10px;font-size:13.5px;font-weight:500;cursor:pointer;'
        + 'border:none;font-family:inherit;transition:.15s;display:inline-flex;align-items:center;justify-content:center;}'
        + '.lm-btn-pri{background:linear-gradient(180deg,#3d84f5,#1f62d8);color:#fff;box-shadow:0 4px 12px rgba(31,98,216,.24);}'
        + '.lm-btn-pri:hover{filter:brightness(1.07);}'
        + '.lm-btn-ghost{background:#f4f7fc;color:#4a5a73;border:1px solid #e2e8f0;}'
        + '.lm-btn-ghost:hover{background:#eef2f9;}'
        + '.lm-toast{position:fixed;top:30px;left:50%;transform:translateX(-50%);display:flex;align-items:center;gap:9px;'
        + 'padding:11px 20px;border-radius:12px;font-size:13.5px;font-weight:500;background:rgba(255,255,255,.96);'
        + 'border:1px solid rgba(226,232,240,.9);box-shadow:0 10px 32px rgba(15,23,42,.14);color:#1e293b;'
        + 'animation:lmDrop .26s cubic-bezier(.21,1.02,.73,1) both;z-index:0;pointer-events:auto;max-width:calc(100vw - 40px);}'
        + '.lm-toast svg{width:19px;height:19px;flex:0 0 auto;}'
        + '.lm-toast .lm-spin{width:17px;height:17px;border:2.5px solid #cfe0f8;border-top-color:#1f6feb;border-radius:50%;animation:lmSpin .7s linear infinite;flex:0 0 auto;}'
        + '.lm-loadbox{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);background:rgba(255,255,255,.96);'
        + 'border-radius:14px;padding:22px 30px;display:flex;flex-direction:column;align-items:center;gap:12px;'
        + 'box-shadow:0 16px 48px rgba(15,23,42,.2);z-index:0;animation:lmPop .2s ease both;}'
        + '.lm-ring{width:34px;height:34px;border:3.5px solid #dbe7f8;border-top-color:#1f6feb;border-radius:50%;animation:lmSpin .75s linear infinite;}'
        + '.lm-loadbox em{font-style:normal;font-size:12.5px;color:#64748b;}'
        + '.lm-in{height:40px;padding:0 14px;border:1px solid #dde4ee;border-radius:10px;font-size:14px;color:#1e293b;'
        + 'font-family:inherit;outline:none;transition:border .15s,box-shadow .15s;}'
        + 'textarea.lm-in{height:auto;min-height:96px;padding:10px 14px;resize:vertical;line-height:1.6;}'
        + '.lm-in:focus{border-color:#4b8ef7;box-shadow:0 0 0 3px rgba(75,142,247,.15);}'
        + '@keyframes lmSpin{to{transform:rotate(360deg)}}'
        + '@keyframes lmFadeIn{from{opacity:0}to{opacity:1}}'
        + '@keyframes lmPop{from{opacity:0;transform:translate(-50%,-50%) scale(.94)}to{opacity:1;transform:translate(-50%,-50%) scale(1)}}'
        + '@keyframes lmDrop{from{opacity:0;transform:translate(-50%,-14px)}to{opacity:1;transform:translate(-50%,0)}}'
        + '@keyframes lmOut{to{opacity:0;transform:translate(-50%,-10px)}}'
        + 'body[data-theme="dark"] .lm-dialog{background:#1e293b;box-shadow:0 24px 64px rgba(0,0,0,.6);}'
        + 'body[data-theme="dark"] .lm-title{color:#e2e8f0;border-bottom-color:rgba(148,163,184,.15);}'
        + 'body[data-theme="dark"] .lm-body{color:#cbd5e1;}'
        + 'body[data-theme="dark"] .lm-toast{background:rgba(30,41,59,.96);border-color:rgba(148,163,184,.2);color:#e2e8f0;}'
        + 'body[data-theme="dark"] .lm-btn-ghost{background:#334155;color:#cbd5e1;border-color:rgba(148,163,184,.25);}'
        + 'body[data-theme="dark"] .lm-loadbox{background:rgba(30,41,59,.96);}';

    var styleEl = document.createElement('style');
    styleEl.id = 'lm-style';
    styleEl.textContent = css;
    document.head.appendChild(styleEl);

    /* ---------- 工具 ---------- */
    var SVG = {
        ok: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.6 2.6L16 9.5"/></svg>',
        err: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
        ask: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.2 9a2.9 2.9 0 015.6 1c0 1.8-2.8 2.2-2.8 3.8"/><circle cx="12" cy="17.2" r="0.4" fill="currentColor"/></svg>',
        info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8h.01M12 11v5"/></svg>',
        x: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>'
    };

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function iconHtml(icon) {
        if (icon === 1 || icon === 'ok') return '<div class="lm-ico ok">' + SVG.ok + '</div>';
        if (icon === 2 || icon === 'err') return '<div class="lm-ico err">' + SVG.err + '</div>';
        if (icon === 3 || icon === 'ask') return '<div class="lm-ico ask">' + SVG.ask + '</div>';
        if (icon === 4 || icon === 'info') return '<div class="lm-ico info">' + SVG.info + '</div>';
        return '';
    }
    function nextIdx() { return counter++; }

    function removeInst(idx) {
        var o = inst[idx];
        if (!o) return;
        delete inst[idx];
        if (o.shade && o.shade.parentNode) o.shade.parentNode.removeChild(o.shade);
        if (o.restore) { try { o.restore(); } catch (e) {} }
        if (o.root && o.root.parentNode) o.root.parentNode.removeChild(o.root);
        if (o.end) { try { o.end(idx); } catch (e) {} }
    }

    function closeAnim(idx) {
        var o = inst[idx];
        if (!o) return;
        if (o.root && o.kind !== 'toast') o.root.style.animation = 'lmOut .16s ease forwards';
        setTimeout(function () { removeInst(idx); }, 150);
    }

    /* ---------- 弹窗（open / alert / confirm 共用） ---------- */
    function buildDialog(opts) {
        var idx = nextIdx();
        var z = ++zIndex;
        var isCenterMsg = !!opts.centerMsg; // alert/confirm 的居中图文形态

        var root = document.createElement('div');
        root.className = 'lm-dialog layui-layer layui-layer-page ' + (opts.skin || '');
        root.style.zIndex = z;
        if (opts.width) root.style.width = opts.width;

        var html = '';
        if (opts.title) {
            html += '<div class="lm-title"><span>' + (opts.titleIsHtml ? opts.title : escHtml(opts.title)) + '</span>';
            if (opts.closeBtn) html += '<button type="button" class="lm-x" aria-label="关闭">' + SVG.x + '</button>';
            html += '</div>';
        } else if (opts.closeBtn) {
            // 无标题但需要关闭按钮：浮动右上角
            html += '<button type="button" class="lm-x" style="position:absolute;top:10px;right:10px;z-index:5;background:rgba(241,245,251,.9)" aria-label="关闭">' + SVG.x + '</button>';
        }

        if (isCenterMsg) {
            html += '<div class="lm-body layui-layer-content" style="padding:0">'
                + '<div class="lm-center">' + iconHtml(opts.icon) + '<div class="lm-text">' + opts.content + '</div></div>'
                + '</div>';
        } else {
            html += '<div class="lm-body layui-layer-content">' + opts.content + '</div>';
        }

        var btns = opts.btns || [];
        if (btns.length) {
            html += '<div class="lm-btns">';
            for (var i = 0; i < btns.length; i++) {
                html += '<button type="button" class="lm-btn ' + (i === 0 ? 'lm-btn-pri' : 'lm-btn-ghost') + '" data-lm-btn="' + i + '">' + escHtml(btns[i]) + '</button>';
            }
            html += '</div>';
        }
        root.innerHTML = html;

        // 遮罩
        var shade = null;
        if (opts.shade !== false) {
            shade = document.createElement('div');
            shade.className = 'lm-shade';
            shade.style.zIndex = z - 1;
            document.body.appendChild(shade);
            if (opts.shadeClose) {
                shade.addEventListener('mousedown', function (e) {
                    if (e.target !== shade) return;
                    if (opts.onShade) opts.onShade(idx);
                    closeAnim(idx);
                });
            }
        }
        document.body.appendChild(root);

        // 内容高度限制（maxHeight 兼容项目自定义参数）
        var body = root.querySelector('.lm-body');
        var maxH = opts.maxHeight;
        if (!maxH) {
            var vh = window.innerHeight || document.documentElement.clientHeight;
            maxH = Math.round(vh * 0.8);
        }
        if (body) {
            body.style.maxHeight = maxH + 'px';
            // 由内容撑开的宽度场景：area 给了 auto 高度时不限制
        }

        // jQuery 对象 / DOM 节点 content：移入并登记还原逻辑
        if (opts.restoreNode) {
            var node = opts.restoreNode;
            var origParent = node.parentNode;
            var origNext = node.nextSibling;
            var origDisplay = node.style.display;
            node.style.display = '';
            var target = root.querySelector('.lm-body');
            target.innerHTML = '';
            target.appendChild(node);
            inst[idx] = {
                root: root, shade: shade, kind: 'dialog',
                end: opts.end,
                restore: function () {
                    try {
                        node.style.display = origDisplay || 'none';
                        if (origParent) origParent.insertBefore(node, origNext);
                        else if (document.body) document.body.appendChild(node);
                    } catch (e) {}
                }
            };
        } else {
            inst[idx] = { root: root, shade: shade, kind: 'dialog', end: opts.end };
        }

        // 关闭按钮
        var xb = root.querySelector('.lm-x');
        if (xb) xb.addEventListener('click', function () {
            if (opts.onClose) { try { opts.onClose(idx); } catch (e) {} }
            closeAnim(idx);
        });

        // 按钮点击
        var btnEls = root.querySelectorAll('[data-lm-btn]');
        for (var b = 0; b < btnEls.length; b++) {
            (function (el) {
                el.addEventListener('click', function () {
                    var n = parseInt(el.getAttribute('data-lm-btn'), 10);
                    var cb = n === 0 ? opts.onYes : (opts['onBtn' + (n + 1)] || opts.onCancel);
                    closeAnim(idx);
                    if (cb) { try { cb(idx); } catch (e) { closeAnim(idx); throw e; } }
                });
            })(btnEls[b]);
        }

        // success 钩子（兼容 layero.find('.layui-layer-content')）
        if (opts.success) {
            try {
                var $o = window.jQuery ? window.jQuery(root) : root;
                opts.success($o, idx);
            } catch (e) {}
        }
        return idx;
    }

    /* ---------- 对外 API ---------- */
    var L = {
        v: '3.7.0-modern',
        __modern: true,
        index: 1,

        open: function (opts) {
            opts = opts || {};
            var content = opts.content == null ? '' : opts.content;
            var restoreNode = null;

            if (content && content.jquery) { restoreNode = content[0]; }
            else if (content && content.nodeType === 1) { restoreNode = content; }
            else if (typeof content !== 'string') { content = String(content); }

            var btns = opts.btn || [];
            if (typeof btns === 'string') btns = [btns];

            var area = opts.area;
            var width = '';
            if (typeof area === 'string' && area !== 'auto') width = area;
            else if (area && area[0] && area[0] !== 'auto') width = area[0];

            return buildDialog({
                title: opts.title || '',
                titleIsHtml: true,
                closeBtn: opts.closeBtn === 1 || opts.closeBtn === true,
                skin: opts.skin || '',
                content: content,
                restoreNode: restoreNode,
                width: width,
                maxHeight: opts.maxHeight,
                shade: opts.shade === false ? false : true,
                shadeClose: !!opts.shadeClose,
                btns: btns,
                onYes: opts.yes || opts.btn1,
                onBtn2: opts.btn2,
                onBtn3: opts.btn3,
                success: opts.success,
                end: opts.end
            });
        },

        alert: function (msg, opts, yes) {
            opts = opts || {};
            return buildDialog({
                centerMsg: true,
                icon: opts.icon,
                content: typeof msg === 'string' ? escHtml(msg) : String(msg),
                title: opts.title || '',
                titleIsHtml: true,
                closeBtn: false,
                skin: opts.skin || '',
                shade: opts.shade === false ? false : true,
                shadeClose: false,
                btns: [opts.btnText || '确定'],
                onYes: yes,
                end: opts.end
            });
        },

        confirm: function (msg, opts, yes, cancel) {
            opts = opts || {};
            return buildDialog({
                centerMsg: true,
                icon: opts.icon === undefined ? 3 : opts.icon,
                content: typeof msg === 'string' ? escHtml(msg) : String(msg),
                title: opts.title || '',
                titleIsHtml: true,
                closeBtn: false,
                skin: opts.skin || '',
                shade: opts.shade === false ? false : true,
                shadeClose: false,
                btns: [opts.btnText || '确定', opts.btnCancelText || '取消'],
                onYes: yes,
                onBtn2: cancel,
                end: opts.end
            });
        },

        msg: function (msg, opts, end) {
            opts = opts || {};
            var idx = nextIdx();
            var sticky = opts.time === false || opts.time === 0;
            var duration = sticky ? 0 : (opts.time || 2000);

            // icon:16 → 居中加载气泡（可带遮罩），否则顶部 Toast
            var el = document.createElement('div');
            if (opts.icon === 16) {
                el.className = 'lm-toast';
                el.innerHTML = '<span class="lm-spin"></span><span>' + escHtml(msg) + '</span>';
                if (opts.shade) {
                    var sd = document.createElement('div');
                    sd.className = 'lm-shade';
                    sd.style.zIndex = ++zIndex;
                    document.body.appendChild(sd);
                    inst[idx] = { root: el, shade: sd, kind: 'toast', end: end };
                } else {
                    inst[idx] = { root: el, kind: 'toast', end: end };
                }
            } else {
                el.className = 'lm-toast';
                var ic = '';
                if (opts.icon === 1) ic = '<span style="color:#16a34a;display:flex">' + SVG.ok + '</span>';
                else if (opts.icon === 2) ic = '<span style="color:#dc2626;display:flex">' + SVG.err + '</span>';
                else if (opts.icon === 3) ic = '<span style="color:#d97706;display:flex">' + SVG.ask + '</span>';
                else ic = '<span style="color:#2563eb;display:flex">' + SVG.info + '</span>';
                el.innerHTML = ic + '<span>' + escHtml(msg) + '</span>';
                inst[idx] = { root: el, kind: 'toast', end: end };
            }
            el.style.zIndex = ++zIndex;
            document.body.appendChild(el);

            if (!sticky) {
                setTimeout(function () {
                    var o = inst[idx];
                    if (o && o.root) o.root.style.animation = 'lmOut .18s ease forwards';
                    setTimeout(function () { removeInst(idx); }, 190);
                }, duration);
            }
            return idx;
        },

        load: function (icon, opts) {
            opts = opts || {};
            var idx = nextIdx();
            var sticky = opts.time === false || opts.time === undefined;
            var z = ++zIndex;

            var sd = document.createElement('div');
            sd.className = 'lm-shade';
            sd.style.zIndex = z;
            sd.style.background = 'rgba(15,23,42,' + (opts.shade === false ? 0 : 0.3) + ')';
            document.body.appendChild(sd);

            var box = document.createElement('div');
            box.className = 'lm-loadbox layui-layer layui-layer-loading';
            box.style.zIndex = z + 1;
            box.innerHTML = '<div class="lm-ring"></div>';
            document.body.appendChild(box);

            inst[idx] = { root: box, shade: sd, kind: 'loading' };
            if (!sticky) setTimeout(function () { closeAnim(idx); }, opts.time || 0);
            return idx;
        },

        prompt: function (opts, cb) {
            opts = opts || {};
            var id = 'lm-prompt-' + nextIdx();
            var isTextarea = opts.formType === 2;
            var inner = '<div style="padding:20px 22px 4px">'
                + (isTextarea
                    ? '<textarea id="' + id + '" class="lm-in" rows="4" placeholder="' + escHtml(opts.placeholder || '请输入内容') + '" style="width:100%;box-sizing:border-box"></textarea>'
                    : '<input id="' + id + '" class="lm-in" placeholder="' + escHtml(opts.placeholder || '请输入内容') + '" style="width:100%;box-sizing:border-box">')
                + '</div>';
            var idx = buildDialog({
                title: opts.title || '请输入',
                content: inner,
                width: opts.area && opts.area[0] ? opts.area[0] : '420px',
                closeBtn: true,
                shadeClose: false,
                btns: [opts.btnText || '确定', opts.btnCancelText || '取消'],
                onYes: function (i) {
                    var val = document.getElementById(id) ? document.getElementById(id).value : '';
                    if (cb) cb(val, i);
                },
                onBtn2: function () {}
            });
            setTimeout(function () {
                var ip = document.getElementById(id);
                if (ip) ip.focus();
            }, 120);
            return idx;
        },

        close: function (idx) {
            if (idx === undefined || idx === null) { L.closeAll(); return; }
            closeAnim(idx);
        },

        closeAll: function (type) {
            for (var k in inst) {
                if (!inst.hasOwnProperty(k)) continue;
                if (type) {
                    var kind = inst[k].kind;
                    if (type === 'dialog' && kind === 'dialog') closeAnim(parseInt(k, 10));
                    else if (type === 'loading' && kind === 'loading') closeAnim(parseInt(k, 10));
                    else if ((type === 'msg' || type === 'toast') && kind === 'toast') closeAnim(parseInt(k, 10));
                } else {
                    closeAnim(parseInt(k, 10));
                }
            }
        }
    };

    window.layer = L;
})();
