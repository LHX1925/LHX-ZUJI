/**
 * 液态玻璃主题 - 全局动画系统 v3.0
 * 包含：滚动触发淡入、涟漪效果、渐变色加载、背景图加载、视差滚动、导航栏滚动阴影
 */
(function() {
    'use strict';

    // ========== 滚动触发淡入动画 ==========
    function initScrollReveal() {
        var reveals = document.querySelectorAll('.glass-reveal');
        if (!reveals.length) return;

        function checkReveal() {
            var windowHeight = window.innerHeight || document.documentElement.clientHeight;
            reveals.forEach(function(el) {
                var top = el.getBoundingClientRect().top;
                var revealPoint = Math.min(120, windowHeight * 0.2);
                if (top < windowHeight - revealPoint) {
                    el.classList.add('visible');
                }
            });
        }

        // 优先使用 IntersectionObserver，滚动进入视口即显示（比滚动事件更可靠）
        if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('visible');
                        observer.unobserve(entry.target);
                    }
                });
            }, { rootMargin: '0px 0px -8% 0px', threshold: 0.01 });
            reveals.forEach(function(el) { observer.observe(el); });
        }

        window.addEventListener('scroll', checkReveal, { passive: true });
        window.addEventListener('resize', checkReveal, { passive: true });
        window.addEventListener('load', checkReveal);
        checkReveal();

        // 兜底：无论滚动事件是否触发，短暂延迟后强制显示所有 reveal，避免内容永久隐藏
        setTimeout(function() {
            reveals.forEach(function(el) { el.classList.add('visible'); });
        }, 1200);

        // 动态插入的 .glass-reveal（AJAX/JS 渲染的卡片、列表等）也要纳入：
        // 上面只在页面加载时收集了一次元素，后来插入的节点不在观察列表里，会被
        // `body.glass-anim-ready .glass-reveal:not(.visible){opacity:0}` 永久隐藏（显示为空白占位）。
        function observeRevealEl(el) {
            if (!el || el.classList.contains('visible')) return;
            var wh = window.innerHeight || document.documentElement.clientHeight;
            var top = el.getBoundingClientRect().top;
            if (top < wh - Math.min(120, wh * 0.2)) {
                // 已在视口内：同步加 .visible（在绘制前完成，避免出现 0.7s 的重复淡入）
                el.classList.add('visible');
                return;
            }
            if ('IntersectionObserver' in window) observer.observe(el);
        }
        if ('MutationObserver' in window && document.body) {
            try {
                var dynObserver = new MutationObserver(function(mutations) {
                    mutations.forEach(function(m) {
                        m.addedNodes.forEach(function(node) {
                            if (node.nodeType !== 1) return;
                            if (node.classList && node.classList.contains('glass-reveal')) {
                                observeRevealEl(node);
                            }
                            if (node.querySelectorAll) {
                                var list = node.querySelectorAll('.glass-reveal:not(.visible)');
                                for (var i = 0; i < list.length; i++) observeRevealEl(list[i]);
                            }
                        });
                    });
                });
                dynObserver.observe(document.body, { childList: true, subtree: true });
            } catch (e) {}
        }
    }

    // ========== 涟漪效果 ==========
    function initRippleEffect() {
        document.addEventListener('click', function(e) {
            var rippleEl = e.target.closest('.glass-ripple');
            if (!rippleEl) return;
            var ripple = document.createElement('span');
            ripple.className = 'ripple-effect';
            var rect = rippleEl.getBoundingClientRect();
            var size = Math.max(rect.width, rect.height);
            ripple.style.width = ripple.style.height = size + 'px';
            ripple.style.left = (e.clientX - rect.left - size / 2) + 'px';
            ripple.style.top = (e.clientY - rect.top - size / 2) + 'px';
            rippleEl.appendChild(ripple);
            ripple.addEventListener('animationend', function() {
                ripple.remove();
            });
        });
    }

    // ========== 加载背景渐变 ==========
    function loadBgGradient() {
        // 优先从 body data-bg-gradient 属性读取（服务端值）
        var body = document.querySelector('body.glass-enabled');
        var savedGrad = (body && body.getAttribute('data-bg-gradient')) || localStorage.getItem('glass_bg_gradient');
        if (!savedGrad || savedGrad === 'default') return;
        if (body && body.classList.contains('glass-bg-image')) return;

        var gradients = {
            'purple': 'linear-gradient(135deg, rgba(245, 243, 255, 0.3) 0%, rgba(237, 233, 254, 0.3) 25%, rgba(248, 245, 255, 0.3) 50%, rgba(243, 240, 255, 0.3) 75%, rgba(245, 243, 255, 0.3) 100%)',
            'rose': 'linear-gradient(135deg, rgba(255, 241, 242, 0.3) 0%, rgba(255, 228, 230, 0.3) 25%, rgba(255, 245, 245, 0.3) 50%, rgba(255, 241, 242, 0.3) 75%, rgba(255, 245, 245, 0.3) 100%)',
            'emerald': 'linear-gradient(135deg, rgba(236, 253, 245, 0.3) 0%, rgba(209, 250, 229, 0.3) 25%, rgba(236, 253, 245, 0.3) 50%, rgba(209, 250, 229, 0.3) 75%, rgba(236, 253, 245, 0.3) 100%)',
            'amber': 'linear-gradient(135deg, rgba(255, 251, 235, 0.3) 0%, rgba(254, 243, 199, 0.3) 25%, rgba(255, 251, 235, 0.3) 50%, rgba(254, 243, 199, 0.3) 75%, rgba(255, 251, 235, 0.3) 100%)',
            'slate': 'linear-gradient(135deg, rgba(248, 250, 252, 0.3) 0%, rgba(226, 232, 240, 0.3) 25%, rgba(248, 250, 252, 0.3) 50%, rgba(226, 232, 240, 0.3) 75%, rgba(248, 250, 252, 0.3) 100%)',
            'sky': 'linear-gradient(135deg, rgba(240, 249, 255, 0.3) 0%, rgba(224, 242, 254, 0.3) 25%, rgba(240, 249, 255, 0.3) 50%, rgba(224, 242, 254, 0.3) 75%, rgba(240, 249, 255, 0.3) 100%)',
            'teal': 'linear-gradient(135deg, rgba(240, 253, 250, 0.3) 0%, rgba(204, 251, 241, 0.3) 25%, rgba(240, 253, 250, 0.3) 50%, rgba(204, 251, 241, 0.3) 75%, rgba(240, 253, 250, 0.3) 100%)'
        };

        if (gradients[savedGrad]) {
            if (body) {
                body.style.background = gradients[savedGrad];
            }
        }
    }

    // ========== 背景图/视频/GIF 轮番（刷新页面自动切换下一张） ==========
    function initBgImageRotation() {
        var body = document.querySelector('body.glass-enabled.glass-bg-image');
        if (!body) return;

        var bgType = body.getAttribute('data-bg-type') || 'image';
        // 视频背景：由 video 标签自动播放，暂不参与轮番
        if (bgType === 'video') return;

        // 优先从 data 属性读取主图，兼容 inline style 未设置的情况
        var mainImage = body.style.getPropertyValue('--glass-bg-image').trim();
        if (!mainImage) {
            var dataMain = body.getAttribute('data-bg-main');
            if (dataMain) {
                mainImage = 'url(\'' + dataMain.trim().replace(/'/g, "\\'") + '\')';
                body.style.setProperty('--glass-bg-image', mainImage);
            }
        }
        var extraImagesAttr = body.getAttribute('data-bg-images');

        var images = [];
        if (mainImage) images.push(mainImage);
        if (extraImagesAttr) {
            extraImagesAttr.split(/[\n,]/).forEach(function(url) {
                url = url.trim();
                if (url) {
                    if (!/^url\(/.test(url)) url = 'url(\'' + url.replace(/'/g, "\\'") + '\')';
                    images.push(url);
                }
            });
        }
        if (images.length < 2) return;

        // 刷新轮番：每次刷新页面自动切换到下一张（localStorage 记录当前索引）
        var KEY = 'glass_bg_rotation_index';
        var stored = parseInt(localStorage.getItem(KEY), 10);
        if (isNaN(stored)) stored = -1;
        var next = (stored + 1) % images.length;
        try { localStorage.setItem(KEY, String(next)); } catch (e) {}

        var currentUrl = images[next];
        body.style.setProperty('--glass-bg-image', currentUrl);

        // GIF 模式：同步更新 div 背景
        var gifEl = document.getElementById('glass-bg-gif');
        if (gifEl) {
            var match = currentUrl.match(/url\(['"]?(.+?)['"]?\)/);
            if (match && match[1]) {
                gifEl.style.backgroundImage = 'url(\'' + match[1].replace(/'/g, "\\'") + '\')';
            }
        }

        // 预加载所有图片/GIF，减少下次切换时的空白
        images.forEach(function(imgUrl) {
            var match = imgUrl.match(/url\(['"]?(.+?)['"]?\)/);
            if (match && match[1]) {
                var preload = new Image();
                preload.src = match[1];
            }
        });
    }

    // ========== 视频背景自动播放/恢复 ==========
    function initBgVideo() {
        var video = document.getElementById('glass-bg-video');
        if (!video) return;
        var body = document.querySelector('body.glass-enabled.glass-bg-image');
        var loop = body ? body.getAttribute('data-bg-video-loop') !== '0' : true;
        var muted = body ? body.getAttribute('data-bg-video-muted') !== '0' : true;
        video.loop = loop;
        video.muted = muted;
        video.playsInline = true;
        var playPromise = video.play();
        if (playPromise && typeof playPromise.then === 'function') {
            playPromise.catch(function(err) {
                // 自动播放失败时，用户首次交互后尝试播放
                var tryPlay = function() {
                    video.play();
                    document.removeEventListener('click', tryPlay);
                    document.removeEventListener('touchstart', tryPlay);
                };
                document.addEventListener('click', tryPlay, { once: true });
                document.addEventListener('touchstart', tryPlay, { once: true });
            });
        }
    }

    // ========== 数字滚动动画 ==========
    function initCountUp() {
        var counters = document.querySelectorAll('.glass-count-up');
        if (!counters.length) return;

        function animateCounter(el) {
            var target = parseInt(el.getAttribute('data-target')) || 0;
            var duration = parseInt(el.getAttribute('data-duration')) || 2000;
            var start = 0;
            var startTime = null;

            function step(timestamp) {
                if (!startTime) startTime = timestamp;
                var progress = Math.min((timestamp - startTime) / duration, 1);
                var eased = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
                var current = Math.floor(eased * target);
                el.textContent = current.toLocaleString();
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = target.toLocaleString();
                }
            }

            requestAnimationFrame(step);
        }

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    animateCounter(entry.target);
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });

        counters.forEach(function(el) {
            observer.observe(el);
        });
    }

    // ========== 视差滚动效果 ==========
    function initParallax() {
        var parallaxEls = document.querySelectorAll('.glass-parallax');
        if (!parallaxEls.length) return;

        window.addEventListener('scroll', function() {
            parallaxEls.forEach(function(el) {
                var speed = parseFloat(el.getAttribute('data-parallax-speed')) || 0.3;
                var yPos = -(window.scrollY * speed);
                el.style.transform = 'translate3d(0, ' + yPos + 'px, 0)';
            });
        }, { passive: true });
    }

    // ========== hover光泽效果 ==========
    function initHoverGlow() {
        document.addEventListener('mousemove', function(e) {
            var glowEls = document.querySelectorAll('.glass-hover-glow');
            glowEls.forEach(function(el) {
                var rect = el.getBoundingClientRect();
                var x = ((e.clientX - rect.left) / rect.width) * 100;
                var y = ((e.clientY - rect.top) / rect.height) * 100;
                el.style.setProperty('--glow-x', x + '%');
                el.style.setProperty('--glow-y', y + '%');
            });
        }, { passive: true });
    }

    // ========== 导航栏滚动阴影 ==========
    function initNavScroll() {
        var navs = document.querySelectorAll('.glass-nav-scroll');
        if (!navs.length) return;

        window.addEventListener('scroll', function() {
            var scrolled = window.scrollY > 10;
            navs.forEach(function(el) {
                if (scrolled) {
                    el.classList.add('scrolled');
                } else {
                    el.classList.remove('scrolled');
                }
            });
        }, { passive: true });
    }

    // ========== 页面加载进度条 ==========
    function initPageLoader() {
        var loader = document.querySelector('.glass-page-loader');
        if (!loader) return;

        var bar = loader.querySelector('.glass-progress-bar');
        if (!bar) return;

        var width = 0;
        var interval = setInterval(function() {
            if (width >= 90) {
                clearInterval(interval);
            }
            width += Math.random() * 20;
            if (width > 90) width = 90;
            bar.style.width = width + '%';
        }, 200);

        window.addEventListener('load', function() {
            bar.style.width = '100%';
            setTimeout(function() {
                loader.style.opacity = '0';
                loader.style.pointerEvents = 'none';
                setTimeout(function() {
                    if (loader.parentNode) loader.parentNode.removeChild(loader);
                }, 400);
            }, 300);
        });
    }

    // ========== 卡片悬浮阴影增强 ==========
    function initCardHover() {
        document.addEventListener('mouseover', function(e) {
            var card = e.target.closest('.glass-card-hover');
            if (!card) return;
            card.style.transition = 'box-shadow 0.2s ease, transform 0.2s ease';
        });

        document.addEventListener('mouseout', function(e) {
            var card = e.target.closest('.glass-card-hover');
            if (!card) return;
            card.style.transition = 'box-shadow 0.4s ease, transform 0.4s ease';
        });
    }

    // ========== 表格行hover波纹 ==========
    function initTableRowHover() {
        document.addEventListener('mouseover', function(e) {
            var row = e.target.closest('tr.glass-row-hover');
            if (!row) return;
            row.style.position = 'relative';
            row.style.zIndex = '1';
        });
        document.addEventListener('mouseout', function(e) {
            var row = e.target.closest('tr.glass-row-hover');
            if (!row) return;
            row.style.position = '';
            row.style.zIndex = '';
        });
    }

    // ========== 图片懒加载淡入 ==========
    function initLazyImages() {
        var imgs = document.querySelectorAll('img.glass-lazy');
        if (!imgs.length) return;

        var imgObserver = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var img = entry.target;
                    if (img.dataset.src) {
                        img.src = img.dataset.src;
                        img.addEventListener('load', function() {
                            img.classList.add('loaded');
                        });
                    }
                    imgObserver.unobserve(img);
                }
            });
        }, { rootMargin: '100px' });

        imgs.forEach(function(img) {
            imgObserver.observe(img);
        });
    }

    // ========== 输入框聚焦动画 ==========
    function initInputFocus() {
        document.addEventListener('focusin', function(e) {
            var input = e.target.closest('.glass-input, .form-control, input[type="text"], input[type="password"], input[type="email"], textarea');
            if (!input) return;
            var parent = input.closest('.glass-input-wrap');
            if (parent) {
                parent.classList.add('focused');
            }
        });
        document.addEventListener('focusout', function(e) {
            var input = e.target.closest('.glass-input, .form-control, input[type="text"], input[type="password"], input[type="email"], textarea');
            if (!input) return;
            var parent = input.closest('.glass-input-wrap');
            if (parent) {
                parent.classList.remove('focused');
            }
        });
    }

    // ========== 初始化所有动画 ==========
    function init() {
        // 标记 JS 已就绪：CSS 仅在此时启用 .glass-reveal 的隐藏→显示动画，
        // JS 加载失败/异常时内容保持默认可见，避免用户中心下方内容永久隐藏。
        if (document.body) document.body.classList.add('glass-anim-ready');
        initScrollReveal();
        initRippleEffect();
        loadBgGradient();
        initBgImageRotation();
        initBgVideo();
        initCountUp();
        initParallax();
        initHoverGlow();
        initNavScroll();
        initPageLoader();
        initCardHover();
        initTableRowHover();
        initLazyImages();
        initInputFocus();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();