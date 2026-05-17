/**
 * Cookie Monster — Privacy & Consent
 *
 * Vanilla JS consent management: geo detection, banner + preferences panel,
 * Google Consent Mode v2 update signals, gated WP-enqueued asset activation,
 * audit-log POST, focus trap, and ESC handling.
 */
(function () {
    'use strict';

    var cfg = window.plcnConfig || {};
    var COOKIE_NAME     = cfg.cookieName     || 'plcn_consent';
    var GEO_COOKIE      = 'plcn_geo';
    var EXPIRY_DAYS     = parseInt(cfg.expiryDays, 10) || 365;
    var COMPLIANCE      = cfg.complianceMode || 'none';
    var POLICY_VERSION  = parseInt(cfg.policyVersion, 10) || 1;
    var CONSENT_MODE_ON = !!cfg.googleConsentMode;
    var LOG_CONSENT     = !!cfg.logConsent;
    var HONOR_DNT       = !!cfg.honorDnt;
    var AJAX_URL        = cfg.ajaxUrl        || '';
    var LOG_NONCE       = cfg.logNonce       || '';
    var CATEGORIES      = cfg.categories     || ['required', 'analytics', 'marketing', 'other'];
    var SCRIPTS_DATA    = cfg.scripts        || {};

    // Synthetic reject-all consent (used when DNT/GPC is detected and honored).
    function rejectAllConsent() {
        return {
            required: true,
            analytics: false,
            marketing: false,
            other: false
        };
    }

    function browserSaysNoTrack() {
        if (!HONOR_DNT) return false;
        if (navigator.doNotTrack === '1' || navigator.doNotTrack === 'yes') return true;
        if (navigator.globalPrivacyControl === true) return true;
        return false;
    }

    /* ------------------------------------------------------------------ */
    /*  Cookie helpers                                                     */
    /* ------------------------------------------------------------------ */
    function getCookie(name) {
        var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : null;
    }

    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 86400000);
        document.cookie = name + '=' + encodeURIComponent(value) +
            ';expires=' + d.toUTCString() +
            ';path=/;SameSite=Lax';
    }

    function getConsent() {
        var raw = getCookie(COOKIE_NAME);
        if (!raw) return null;
        try {
            var parsed = JSON.parse(raw);
            // Stale-policy guard.
            if (!parsed || (parsed.version || 0) < POLICY_VERSION) return null;
            return parsed;
        } catch (e) { return null; }
    }

    function setConsent(consent) {
        consent.timestamp = Math.floor(Date.now() / 1000);
        consent.version   = POLICY_VERSION;
        setCookie(COOKIE_NAME, JSON.stringify(consent), EXPIRY_DAYS);
    }

    function hasDecided() {
        return getConsent() !== null || getCookie('plcn_dismissed') !== null;
    }

    /* ------------------------------------------------------------------ */
    /*  Geo detection                                                      */
    /* ------------------------------------------------------------------ */
    function plcnGetRegion(callback) {
        var cached = getCookie(GEO_COOKIE);
        if (cached) { callback(cached); return; }
        if (!AJAX_URL) { callback('other'); return; }

        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var region = 'other';
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success && resp.data && resp.data.region) {
                        region = resp.data.region;
                    }
                } catch (e) {}
            }
            setCookie(GEO_COOKIE, region, 1);
            callback(region);
        };
        xhr.send('action=plcn_geo_detect');
    }

    window.plcnGetRegion = plcnGetRegion;

    function getEffectiveMode(region) {
        if (COMPLIANCE === 'both') {
            if (region === 'eu')         return 'gdpr';
            if (region === 'california') return 'ccpa';
            return 'gdpr';
        }
        return COMPLIANCE;
    }

    /* ------------------------------------------------------------------ */
    /*  Google Consent Mode v2                                             */
    /* ------------------------------------------------------------------ */
    function updateGoogleConsent(consent) {
        if (!CONSENT_MODE_ON) return;
        if (typeof window.gtag !== 'function') {
            // The default signal in <head> defines gtag for us; if it didn't run, bail.
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
        }
        var analytics = consent && consent.analytics ? 'granted' : 'denied';
        var marketing = consent && consent.marketing ? 'granted' : 'denied';
        window.gtag('consent', 'update', {
            ad_storage:           marketing,
            ad_user_data:         marketing,
            ad_personalization:   marketing,
            analytics_storage:    analytics,
            personalization_storage: analytics
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Script injection — registered scripts                              */
    /* ------------------------------------------------------------------ */
    var injectedHandles = {};

    function injectScript(script) {
        if (injectedHandles[script.handle]) return;
        injectedHandles[script.handle] = true;

        var location = script.load_in || 'head';
        var parent = location === 'footer' ? document.body : document.head;

        if (script.src) {
            var el = document.createElement('script');
            el.src = script.src;
            if (script.attrs) {
                script.attrs.forEach(function (a) { el.setAttribute(a, ''); });
            }
            parent.appendChild(el);
        }

        if (script.inline) {
            var inl = document.createElement('script');
            inl.textContent = script.inline;
            parent.appendChild(inl);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Activation of gated WP-enqueued scripts/styles                     */
    /* ------------------------------------------------------------------ */
    function activateGatedAssets(consent, mode) {
        var nodes = document.querySelectorAll('script[type="text/plain"][data-plcn-category]');
        nodes.forEach(function (node) {
            var cat = node.getAttribute('data-plcn-category');
            if (!shouldActivate(cat, consent, mode)) return;

            // Recreate so the browser actually parses/executes.
            var fresh = document.createElement('script');
            for (var i = 0; i < node.attributes.length; i++) {
                var a = node.attributes[i];
                if (a.name === 'type' || a.name.indexOf('data-plcn-') === 0) continue;
                fresh.setAttribute(a.name, a.value);
            }
            fresh.type = 'text/javascript';
            if (node.src) {
                fresh.src = node.src;
            } else {
                fresh.text = node.textContent;
            }
            node.parentNode.insertBefore(fresh, node);
            node.parentNode.removeChild(node);
        });

        var styles = document.querySelectorAll('link[data-plcn-style="1"][data-plcn-category]');
        styles.forEach(function (link) {
            var cat = link.getAttribute('data-plcn-category');
            if (!shouldActivate(cat, consent, mode)) return;
            link.rel = 'stylesheet';
            link.removeAttribute('as');
            link.removeAttribute('data-plcn-style');
        });
    }

    function shouldActivate(cat, consent, mode) {
        if (cat === 'required') return true;
        if (mode === 'none')    return true;
        if (mode === 'ccpa') {
            return !consent || consent[cat] !== false;
        }
        return !!(consent && consent[cat] === true);
    }

    function activateConsentedScripts(consent, mode) {
        // Registered scripts.
        if (SCRIPTS_DATA && typeof SCRIPTS_DATA === 'object') {
            Object.keys(SCRIPTS_DATA).forEach(function (handle) {
                var s = SCRIPTS_DATA[handle];
                if (shouldActivate(s.category || 'other', consent, mode)) {
                    injectScript(s);
                }
            });
        }

        // Gated WP-enqueued assets.
        activateGatedAssets(consent, mode);

        // Blocked embeds (auto-load when category is now allowed).
        activateBlockedEmbeds(consent, mode);
    }

    /* ------------------------------------------------------------------ */
    /*  Embed activation                                                   */
    /* ------------------------------------------------------------------ */
    function activateBlockedEmbeds(consent, mode) {
        var wraps = document.querySelectorAll('.plcn-embed-wrap[data-plcn-embed-category]');
        wraps.forEach(function (wrap) {
            var cat = wrap.getAttribute('data-plcn-embed-category');
            if (!shouldActivate(cat, consent, mode)) return;
            loadEmbed(wrap);
        });
    }

    function loadEmbed(wrap) {
        var iframe = wrap.querySelector('iframe[data-plcn-blocked="1"]');
        if (!iframe) return;
        var realSrc = iframe.getAttribute('data-plcn-src');
        if (!realSrc) return;

        iframe.setAttribute('src', realSrc);
        iframe.removeAttribute('data-plcn-blocked');
        iframe.removeAttribute('data-plcn-src');

        var placeholder = wrap.querySelector('.plcn-embed-placeholder');
        var holder      = wrap.querySelector('.plcn-embed-iframe-holder');
        if (placeholder) placeholder.style.display = 'none';
        if (holder)      holder.removeAttribute('hidden');
    }

    /* ------------------------------------------------------------------ */
    /*  Audit log                                                          */
    /* ------------------------------------------------------------------ */
    function logConsentEvent(consent, action, region) {
        if (!LOG_CONSENT || !AJAX_URL) return;
        var data = 'action=plcn_record_consent' +
            '&nonce=' + encodeURIComponent(LOG_NONCE) +
            '&plcn_action=' + encodeURIComponent(action || '') +
            '&region=' + encodeURIComponent(region || '') +
            '&categories=' + encodeURIComponent(JSON.stringify(consent || {}));
        var xhr = new XMLHttpRequest();
        xhr.open('POST', AJAX_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.send(data);
    }

    /* ------------------------------------------------------------------ */
    /*  DOM helpers                                                        */
    /* ------------------------------------------------------------------ */
    function $(id) { return document.getElementById(id); }

    function showBanner() {
        var banner = $('plcn-banner');
        var backdrop = $('plcn-overlay-backdrop');
        if (banner) banner.classList.add('plcn-active');
        if (backdrop && banner && banner.classList.contains('plcn-pos-overlay')) {
            backdrop.classList.add('plcn-active');
        }
    }

    function hideBanner() {
        var banner = $('plcn-banner');
        var backdrop = $('plcn-overlay-backdrop');
        if (banner) banner.classList.remove('plcn-active');
        if (backdrop) backdrop.classList.remove('plcn-active');
    }

    var prevFocus = null;
    function showPreferences() {
        var panel = $('plcn-preferences');
        var backdrop = $('plcn-overlay-backdrop');
        if (panel) {
            var consent = getConsent() || {};
            CATEGORIES.forEach(function (cat) {
                var toggle = panel.querySelector('[data-category="' + cat + '"]');
                if (!toggle) return;
                if (cat === 'required') {
                    toggle.checked = true;
                    toggle.disabled = true;
                } else if (consent[cat] !== undefined) {
                    toggle.checked = !!consent[cat];
                } else {
                    toggle.checked = (window._plcnEffectiveMode === 'ccpa' || window._plcnEffectiveMode === 'none');
                }
            });
            panel.classList.add('plcn-active');
            panel.setAttribute('aria-hidden', 'false');
            prevFocus = document.activeElement;
            var first = panel.querySelector('input:not([disabled]), button');
            if (first) first.focus();
        }
        if (backdrop) backdrop.classList.add('plcn-active');
        hideBanner();
    }

    function hidePreferences() {
        var panel = $('plcn-preferences');
        var backdrop = $('plcn-overlay-backdrop');
        if (panel) {
            panel.classList.remove('plcn-active');
            panel.setAttribute('aria-hidden', 'true');
        }
        if (backdrop) backdrop.classList.remove('plcn-active');
        if (prevFocus && typeof prevFocus.focus === 'function') prevFocus.focus();
    }

    /* ------------------------------------------------------------------ */
    /*  Focus trap                                                         */
    /* ------------------------------------------------------------------ */
    function trapFocus(e) {
        var panel = $('plcn-preferences');
        if (!panel || !panel.classList.contains('plcn-active')) return;
        if (e.key !== 'Tab') return;

        var focusable = panel.querySelectorAll('input:not([disabled]), button, [href], [tabindex]:not([tabindex="-1"])');
        if (!focusable.length) return;
        var first = focusable[0];
        var last  = focusable[focusable.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Event handlers                                                     */
    /* ------------------------------------------------------------------ */
    function handleBannerAction(action, mode, region) {
        var consent = { required: true };

        if (action === 'accept-all') {
            CATEGORIES.forEach(function (c) { consent[c] = true; });
        } else if (action === 'reject-all') {
            CATEGORIES.forEach(function (c) { consent[c] = (c === 'required'); });
        } else if (action === 'manage-preferences') {
            showPreferences();
            return;
        }

        setConsent(consent);
        hideBanner();
        activateConsentedScripts(consent, mode);
        updateGoogleConsent(consent);
        logConsentEvent(consent, action, region);
    }

    function handleSavePreferences(mode, region) {
        var panel = $('plcn-preferences');
        if (!panel) return;

        var consent = { required: true };
        CATEGORIES.forEach(function (cat) {
            if (cat === 'required') return;
            var toggle = panel.querySelector('[data-category="' + cat + '"]');
            consent[cat] = toggle ? toggle.checked : false;
        });

        setConsent(consent);
        hidePreferences();
        activateConsentedScripts(consent, mode);
        updateGoogleConsent(consent);
        logConsentEvent(consent, 'save-preferences', region);
    }

    /* ------------------------------------------------------------------ */
    /*  Initialization                                                     */
    /* ------------------------------------------------------------------ */
    function init() {
        if (COMPLIANCE === 'both') {
            plcnGetRegion(function (region) { boot(region); });
        } else {
            boot('other');
        }
    }

    function boot(region) {
        var mode = getEffectiveMode(region);
        window._plcnEffectiveMode = mode;

        // DNT / GPC honored → treat as Reject All. Don't show banner; don't
        // load any optional scripts. Tell Google Consent Mode to deny.
        if (browserSaysNoTrack()) {
            var rejected = rejectAllConsent();
            activateConsentedScripts(rejected, 'gdpr');
            updateGoogleConsent(rejected);
            return;
        }

        // Already decided? Activate and exit.
        if (hasDecided()) {
            var consent = getConsent();
            if (!consent && getCookie('plcn_dismissed')) {
                consent = { required: true, analytics: true, marketing: true, other: true };
            }
            activateConsentedScripts(consent, mode);
            updateGoogleConsent(consent);
            return;
        }

        // CCPA / none: scripts load immediately under opt-out model.
        if (mode === 'ccpa' || mode === 'none') {
            activateConsentedScripts(null, mode);
        }

        showBanner();

        var banner = $('plcn-banner');
        if (banner) {
            banner.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                handleBannerAction(btn.getAttribute('data-action'), mode, region);
            });
        }

        var prefs = $('plcn-preferences');
        if (prefs) {
            prefs.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-action]');
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                if (action === 'save-preferences') {
                    handleSavePreferences(mode, region);
                } else if (action === 'cancel-preferences') {
                    hidePreferences();
                    showBanner();
                }
            });
        }

        var backdrop = $('plcn-overlay-backdrop');
        if (backdrop) {
            backdrop.addEventListener('click', function () {
                hidePreferences();
                showBanner();
            });
        }

        // Re-open preferences from external triggers (shortcode, menu link).
        document.addEventListener('click', function (e) {
            var link = e.target.closest('.plcn-open-preferences');
            if (link) {
                e.preventDefault();
                if (link.getAttribute('data-plcn-preset') === 'reject-marketing' && prefs) {
                    var mk = prefs.querySelector('[data-category="marketing"]');
                    if (mk) mk.checked = false;
                }
                showPreferences();
                return;
            }

            // Per-embed one-time "Load this content" button.
            var oneShot = e.target.closest('.plcn-embed-load');
            if (oneShot) {
                e.preventDefault();
                var wrap = oneShot.closest('.plcn-embed-wrap');
                if (wrap) loadEmbed(wrap);
            }
        });

        // ESC + focus trap.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && prefs && prefs.classList.contains('plcn-active')) {
                hidePreferences();
                showBanner();
            }
            trapFocus(e);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
