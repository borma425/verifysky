// Runtime script for slider challenge and telemetry collection.

(function() {
  'use strict';

  // ---- Anti-Debugging Module (_db) ----
  // Detects DevTools, debuggers, and tampered environments.
  // These checks slow down reverse engineering but are NOT the core security.

  var _db = {
    _t0: Date.now(),
    _checks: 0,

    // Detect debugger pauses via timing gaps
    _tick: function() {
      var _n = Date.now();
      if (_n - _db._t0 > 200 && _db._checks > 2) {
        // Possible debugger pause detected — don't block, just note it
        _db._tampered = true;
      }
      _db._t0 = _n;
      _db._checks++;
    },

    // Detect devtools via console timing
    _probe: function() {
      var _s = performance.now();
      // console.log with %c formatting takes longer when DevTools is open
      // We don't actually log — just measure the call overhead
      try { console.log.apply(null); } catch(e) {}
      return (performance.now() - _s) > 50;
    },

    _tampered: false,

    // Check for common automation frameworks
    _env: function() {
      var _w = window;
      var _signs = [
        '_phantom', '__nightmare', '_selenium', 'callPhantom',
        'webdriver', '__webdriver_evaluate', '__driver_evaluate',
        'domAutomation', 'domAutomationController',
        '_Recaptcha', '__coverage__'
      ];
      for (var i = 0; i < _signs.length; i++) {
        if (_signs[i] in _w) return true;
      }
      // Check navigator.webdriver
      if (navigator.webdriver === true) return true;
      // Check for modified user-agent consistency
      if (/HeadlessChrome|PhantomJS|Nightmare/i.test(navigator.userAgent)) return true;
      return false;
    }
  };

  // Run anti-debug tick periodically
  var _dbInterval = setInterval(_db._tick, 100);

  // ---- Deep Fingerprint Module (_fp) ----
  // Collects 15+ signals to create a unique device identifier.

  var _fp = {
    // Core browser properties
    _nav: function() {
      var n = navigator;
      return [
        n.userAgent || '',
        n.language || '',
        n.languages ? n.languages.join(',') : '',
        String(n.hardwareConcurrency || ''),
        String(n.maxTouchPoints || 0),
        n.platform || '',
        String(screen.width) + 'x' + String(screen.height),
        String(screen.availWidth) + 'x' + String(screen.availHeight),
        String(screen.colorDepth),
        String(screen.pixelDepth || ''),
        String(new Date().getTimezoneOffset()),
        Intl && Intl.DateTimeFormat ? Intl.DateTimeFormat().resolvedOptions().timeZone : '',
        String(!!window.sessionStorage),
        String(!!window.localStorage),
        String(!!window.indexedDB),
        String(!!window.openDatabase),
      ];
    },

    // Canvas 2D fingerprint — draws text and shapes, extracts pixel hash
    _canvas: function() {
      try {
        var c = document.createElement('canvas');
        c.width = 280;
        c.height = 60;
        var x = c.getContext('2d');
        if (!x) return 'canvas-no-ctx';

        // Text rendering (varies by font engine and GPU)
        x.textBaseline = 'alphabetic';
        x.fillStyle = '#f60';
        x.fillRect(125, 1, 62, 20);

        x.fillStyle = '#069';
        x.font = '11pt no-real-font-123';
        x.fillText('Cwm fjord veg balks', 2, 15);

        x.fillStyle = 'rgba(102, 204, 0, 0.7)';
        x.font = '18pt Arial';
        x.fillText('Verification Active', 4, 45);

        // Geometric shapes (vary by anti-aliasing implementation)
        x.globalCompositeOperation = 'multiply';
        x.fillStyle = 'rgb(255,0,255)';
        x.beginPath();
        x.arc(50, 50, 50, 0, Math.PI * 2, true);
        x.closePath();
        x.fill();

        x.fillStyle = 'rgb(0,255,255)';
        x.beginPath();
        x.arc(100, 50, 50, 0, Math.PI * 2, true);
        x.closePath();
        x.fill();

        return c.toDataURL();
      } catch(e) {
        return 'canvas-error';
      }
    },

    // WebGL fingerprint — GPU vendor, renderer, extensions, parameters
    _webgl: function() {
      try {
        var c = document.createElement('canvas');
        var gl = c.getContext('webgl') || c.getContext('experimental-webgl');
        if (!gl) return 'webgl-unavailable';

        var parts = [];

        // Renderer info (most unique signal)
        var dbg = gl.getExtension('WEBGL_debug_renderer_info');
        if (dbg) {
          parts.push(gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL));
          parts.push(gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL));
        }

        // Key parameters that vary by GPU/driver
        parts.push(String(gl.getParameter(gl.MAX_VERTEX_ATTRIBS)));
        parts.push(String(gl.getParameter(gl.MAX_VARYING_VECTORS)));
        parts.push(String(gl.getParameter(gl.MAX_VERTEX_UNIFORM_VECTORS)));
        parts.push(String(gl.getParameter(gl.MAX_FRAGMENT_UNIFORM_VECTORS)));
        parts.push(String(gl.getParameter(gl.MAX_TEXTURE_SIZE)));
        parts.push(String(gl.getParameter(gl.MAX_RENDERBUFFER_SIZE)));
        parts.push(String(gl.getParameter(gl.ALIASED_LINE_WIDTH_RANGE)));
        parts.push(String(gl.getParameter(gl.ALIASED_POINT_SIZE_RANGE)));

        // Supported extensions list
        var exts = gl.getSupportedExtensions();
        if (exts) parts.push(exts.sort().join(','));

        // Shader precision format (varies by GPU)
        try {
          var hp = gl.getShaderPrecisionFormat(gl.FRAGMENT_SHADER, gl.HIGH_FLOAT);
          if (hp) parts.push(hp.precision + ':' + hp.rangeMin + ':' + hp.rangeMax);
        } catch(e) {}

        return parts.join('|');
      } catch(e) {
        return 'webgl-error';
      }
    },

    // Audio fingerprint — OfflineAudioContext oscillator rendering
    _audio: function() {
      return new Promise(function(resolve) {
        try {
          var AudioCtx = window.OfflineAudioContext || window.webkitOfflineAudioContext;
          if (!AudioCtx) { resolve('audio-unavailable'); return; }

          var ctx = new AudioCtx(1, 44100, 44100);
          var osc = ctx.createOscillator();
          osc.type = 'triangle';
          osc.frequency.setValueAtTime(10000, ctx.currentTime);

          var comp = ctx.createDynamicsCompressor();
          comp.threshold.setValueAtTime(-50, ctx.currentTime);
          comp.knee.setValueAtTime(40, ctx.currentTime);
          comp.ratio.setValueAtTime(12, ctx.currentTime);
          comp.attack.setValueAtTime(0, ctx.currentTime);
          comp.release.setValueAtTime(0.25, ctx.currentTime);

          osc.connect(comp);
          comp.connect(ctx.destination);
          osc.start(0);

          ctx.startRendering().then(function(buffer) {
            var data = buffer.getChannelData(0);
            var sum = 0;
            for (var i = 4500; i < 5000; i++) {
              sum += Math.abs(data[i]);
            }
            resolve(sum.toString());
          }).catch(function() {
            resolve('audio-render-fail');
          });

          // Timeout fallback
          setTimeout(function() { resolve('audio-timeout'); }, 1000);
        } catch(e) {
          resolve('audio-error');
        }
      });
    },

    // Font detection — probes rendered widths of test strings
    _fonts: function() {
      var baseFonts = ['monospace', 'sans-serif', 'serif'];
      var testFonts = [
        'Arial', 'Arial Black', 'Comic Sans MS', 'Courier New', 'Georgia',
        'Impact', 'Lucida Console', 'Palatino Linotype', 'Tahoma',
        'Times New Roman', 'Trebuchet MS', 'Verdana',
        'Lucida Sans Unicode', 'Microsoft Sans Serif', 'Segoe UI'
      ];

      var testStr = 'mmmmmmmmmmlli';
      var testSize = '72px';
      var detected = [];

      var span = document.createElement('span');
      span.style.position = 'absolute';
      span.style.left = '-9999px';
      span.style.fontSize = testSize;
      span.innerText = testStr;
      document.body.appendChild(span);

      // Get base widths
      var baseWidths = {};
      for (var b = 0; b < baseFonts.length; b++) {
        span.style.fontFamily = baseFonts[b];
        baseWidths[baseFonts[b]] = span.offsetWidth;
      }

      // Test each font
      for (var f = 0; f < testFonts.length; f++) {
        for (var j = 0; j < baseFonts.length; j++) {
          span.style.fontFamily = '"' + testFonts[f] + '",' + baseFonts[j];
          if (span.offsetWidth !== baseWidths[baseFonts[j]]) {
            detected.push(testFonts[f]);
            break;
          }
        }
      }

      document.body.removeChild(span);
      return detected.join(',');
    },

    // Combine all signals into a single fingerprint hash
    _compute: async function() {
      var signals = _fp._nav();
      signals.push(_fp._canvas());
      signals.push(_fp._webgl());
      signals.push(_fp._fonts());

      // Async signals
      var audioFp = await _fp._audio();
      signals.push(audioFp);

      // Environment integrity flag
      signals.push(String(_db._env()));
      signals.push(String(_db._tampered));

      return await _fp._hash(signals);
    },

    // Returns a strict 64-hex fingerprint to satisfy server-side validation.
    _hash: async function(arr) {
      var str = arr.join('\x00');
      try {
        if (window.crypto && crypto.subtle && window.TextEncoder) {
          var data = new TextEncoder().encode(str);
          var digest = await crypto.subtle.digest('SHA-256', data);
          var bytes = new Uint8Array(digest);
          var hex = '';
          for (var i = 0; i < bytes.length; i++) {
            hex += bytes[i].toString(16).padStart(2, '0');
          }
          return hex;
        }
      } catch (e) {}

      // Fallback (no subtle crypto): build deterministic 64-hex output
      var h1 = 0x811c9dc5 >>> 0;
      var h2 = 0x9e3779b1 >>> 0;
      var h3 = 0x85ebca6b >>> 0;
      var h4 = 0xc2b2ae35 >>> 0;
      for (var j = 0; j < str.length; j++) {
        var c = str.charCodeAt(j);
        h1 = Math.imul(h1 ^ c, 0x1000193) >>> 0;
        h2 = Math.imul(h2 ^ c, 0x27d4eb2d) >>> 0;
        h3 = Math.imul(h3 ^ c, 0x165667b1) >>> 0;
        h4 = Math.imul(h4 ^ c, 0x9e3779b1) >>> 0;
      }
      var p1 = (h1 >>> 0).toString(16).padStart(8, '0');
      var p2 = (h2 >>> 0).toString(16).padStart(8, '0');
      var p3 = (h3 >>> 0).toString(16).padStart(8, '0');
      var p4 = (h4 >>> 0).toString(16).padStart(8, '0');
      return (p1 + p2 + p3 + p4 + p1 + p2 + p3 + p4).slice(0, 64);
    }
  };

  // ---- Telemetry Module (_tm) ----
  // Records fine-grained mouse/touch movement data with sub-ms precision.

  var _tm = {
    _data: [],
    _active: false,
    _maxPoints: 1500,

    _start: function() {
      _tm._data = [];
      _tm._active = true;
    },

    _record: function(e) {
      if (!_tm._active) return;
      if (_tm._data.length >= _tm._maxPoints) return;

      var cx = 0, cy = 0;
      if (e.type.indexOf('touch') >= 0) {
        var t = e.touches && e.touches[0] ? e.touches[0] : (e.changedTouches ? e.changedTouches[0] : null);
        if (t) { cx = t.clientX; cy = t.clientY; }
      } else {
        cx = e.clientX;
        cy = e.clientY;
      }

      _tm._data.push([
        Math.round(cx * 10) / 10,  // Sub-pixel precision
        Math.round(cy * 10) / 10,
        performance.now() | 0       // High-resolution timestamp (ms integer)
      ]);
    },

    _stop: function() {
      _tm._active = false;
    },

    _get: function() {
      return _tm._data.slice();
    }
  };

  // ---- Slider Module (_sl) ----
  // Manages the visual slider interaction and delegates events.

  var _sl = {
    _handle: null,
    _track: null,
    _label: null,
    _status: null,
    _timer: null,
    _container: null,
    _hole: null,
    _piece: null,
    _maxSlide: 0,
    _targetX: 0,
    _currentX: 0,
    _startX: 0,
    _dragging: false,
    _submitted: false,

    _decodeTargetX: function() {
      var C = window.__ES_CHALLENGE || {};
      if (!C || typeof C.targetHint !== 'string') return Math.floor(_sl._maxSlide / 2);
      var parts = C.targetHint.split('.');
      if (parts.length !== 2) return Math.floor(_sl._maxSlide / 2);
      var salt = parseInt(parts[0], 16);
      var obf = parseInt(parts[1], 16);
      var noncePart = parseInt(String(C.nonce || '').substring(0, 8), 16);
      var sigPart = parseInt(String(C.signature || '').substring(0, 8), 16);
      if (!isFinite(salt) || !isFinite(obf) || !isFinite(noncePart) || !isFinite(sigPart)) {
        return Math.floor(_sl._maxSlide / 2);
      }
      var mask = (noncePart ^ sigPart ^ (salt & 0xffff)) & 0x3ff;
      var decoded = (obf ^ mask) & 0x3ff;
      return Math.max(0, Math.min(decoded, _sl._maxSlide));
    },

    _positionHole: function() {
      if (!_sl._hole) return;
      var holeX = Math.max(0, Math.min(_sl._targetX, _sl._maxSlide));
      _sl._hole.style.left = holeX + 'px';
    },

    _init: function() {
      _sl._handle = document.getElementById('sliderHandle');
      _sl._track = document.getElementById('sliderTrack');
      _sl._label = document.getElementById('sliderLabel');
      _sl._status = document.getElementById('statusBar');
      _sl._timer = document.getElementById('timerFill');
      _sl._container = document.getElementById('sliderContainer');
      _sl._hole = document.getElementById('puzzleHole');
      _sl._piece = document.getElementById('puzzlePiece');
      _sl._maxSlide = window.__ES_CHALLENGE.trackWidth - 44;
      _sl._targetX = _sl._decodeTargetX();
      _sl._positionHole();
      _tx._initTurnstile();

      window.addEventListener('resize', function() {
        _sl._maxSlide = window.__ES_CHALLENGE.trackWidth - 44;
        _sl._positionHole();
      });

      // Animate timer bar
      requestAnimationFrame(function() {
        if (_sl._timer) _sl._timer.classList.add('active');
      });

      // Expiry countdown
      var _ex = setInterval(function() {
        if (Date.now() > window.__ES_CHALLENGE.expiresAt) {
          clearInterval(_ex);
          clearInterval(_dbInterval);
          _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
          if (_sl._container) _sl._container.style.pointerEvents = 'none';
        }
      }, 1000);

      // Bind events
      _sl._handle.addEventListener('mousedown', _sl._onStart);
      document.addEventListener('mousemove', _sl._onMove);
      document.addEventListener('mouseup', _sl._onEnd);
      _sl._handle.addEventListener('touchstart', _sl._onStart, { passive: false });
      document.addEventListener('touchmove', _sl._onMove, { passive: false });
      document.addEventListener('touchend', _sl._onEnd);

      // Prevent context menu on slider
      _sl._handle.addEventListener('contextmenu', function(e) { e.preventDefault(); });
    },

    _onStart: function(e) {
      if (_sl._submitted) return;
      _sl._dragging = true;
      var cx = e.type.indexOf('touch') >= 0 ? e.touches[0].clientX : e.clientX;
      _sl._startX = cx - _sl._currentX;
      if (_sl._label) _sl._label.style.opacity = '0';
      _tm._start();
      _tm._record(e);
    },

    _onMove: function(e) {
      if (!_sl._dragging || _sl._submitted) return;
      e.preventDefault();
      var cx = e.type.indexOf('touch') >= 0 ? e.touches[0].clientX : e.clientX;
      var nx = Math.max(0, Math.min(cx - _sl._startX, _sl._maxSlide));
      _sl._currentX = nx;
      _sl._handle.style.left = nx + 'px';
      _sl._track.style.width = (nx + 22) + 'px';
      if (_sl._piece) _sl._piece.style.left = nx + 'px';
      _tm._record(e);
    },

    _onEnd: function(e) {
      if (!_sl._dragging || _sl._submitted) return;
      _sl._dragging = false;
      _tm._record(e);
      _tm._stop();
      _tx._submit();
    },

    _setStatus: function(msg, type) {
      if (_sl._status) {
        _sl._status.textContent = msg;
        _sl._status.className = 'status-bar' + (type ? ' ' + type : '');
      }
    },

    _reset: function() {
      _sl._submitted = false;
      _sl._currentX = 0;
      _sl._handle.style.left = '0px';
      _sl._track.style.width = '0px';
      if (_sl._piece) {
        _sl._piece.style.left = '0px';
      }
      if (_sl._label) _sl._label.style.opacity = '1';
      try {
        _tx._turnstileToken = null;
        if (window.turnstile && _tx._turnstileWidgetId !== null) {
          window.turnstile.reset(_tx._turnstileWidgetId);
          window.turnstile.execute(_tx._turnstileWidgetId);
        }
      } catch (e) {}
    },

    _lock: function() {
      _sl._submitted = true;
      if (_sl._container) _sl._container.style.pointerEvents = 'none';
    }
  };

  // ---- Transmission Module (_tx) ----
  // Handles payload construction and submission to the dynamic endpoint.

  var _tx = {
    _turnstileToken: null,
    _turnstileWidgetId: null,
    _turnstileBootstrapTimer: null,
    _turnstileBootstrapChecks: 0,
    _initTurnstile: function() {
      try {
        if (!window.turnstile) {
          if (_tx._turnstileBootstrapTimer) return;
          _tx._turnstileBootstrapChecks = 0;
          _tx._turnstileBootstrapTimer = setInterval(function() {
            _tx._turnstileBootstrapChecks++;
            if (window.turnstile) {
              clearInterval(_tx._turnstileBootstrapTimer);
              _tx._turnstileBootstrapTimer = null;
              _tx._initTurnstile();
              return;
            }
            if (_tx._turnstileBootstrapChecks > 80) {
              clearInterval(_tx._turnstileBootstrapTimer);
              _tx._turnstileBootstrapTimer = null;
            }
          }, 250);
          return;
        }

        if (_tx._turnstileWidgetId !== null) {
          try { window.turnstile.execute(_tx._turnstileWidgetId); } catch (e) {}
          return;
        }
        var C = window.__ES_CHALLENGE || {};
        if (!C.siteKey) return;
        var el = document.getElementById('tsHidden');
        if (!el) return;
        _tx._turnstileWidgetId = window.turnstile.render(el, {
          sitekey: C.siteKey,
          size: 'invisible',
          retry: 'auto',
          'retry-interval': 800,
          callback: function(token) { _tx._turnstileToken = token; },
          'expired-callback': function() { _tx._turnstileToken = null; },
          'error-callback': function() { _tx._turnstileToken = null; }
        });
        try { window.turnstile.execute(_tx._turnstileWidgetId); } catch (e) {}
      } catch (e) {}
    },
    _disableInterceptors: function() {
      try {
        if ('serviceWorker' in navigator && navigator.serviceWorker.getRegistrations) {
          navigator.serviceWorker.getRegistrations().then(function(regs) {
            for (var i = 0; i < regs.length; i++) {
              try { regs[i].unregister(); } catch (e) {}
            }
          }).catch(function() {});
        }
      } catch (e) {}
      try {
        if (window.caches && caches.keys) {
          caches.keys().then(function(keys) {
            for (var i = 0; i < keys.length; i++) {
              try { caches.delete(keys[i]); } catch (e) {}
            }
          }).catch(function() {});
        }
      } catch (e) {}
    },
    _waitForToken: function(maxChecks, intervalMs, onTick) {
      return new Promise(function(resolve) {
        var checks = 0;
        var timer = setInterval(function() {
          checks++;
          if (_tx._turnstileToken) {
            clearInterval(timer);
            resolve(true);
            return;
          }
          if (typeof onTick === 'function') {
            try { onTick(checks); } catch (e) {}
          }
          if (checks >= maxChecks) {
            clearInterval(timer);
            resolve(false);
          }
        }, intervalMs);
      });
    },
    _postJson: function(url, payload, nonce) {
      return fetch(url, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-ES-Nonce': nonce.substring(0, 16)
        },
        cache: 'no-store',
        body: JSON.stringify(payload),
        credentials: 'same-origin'
      }).then(function(resp) {
        return resp.text();
      }).then(function(text) {
        try { return JSON.parse(text); } catch (e) { return null; }
      }).catch(function() {
        return null;
      });
    },

    _submit: async function() {
      if (_sl._submitted) return;
      _sl._submitted = true;
      _sl._setStatus('Verifying...', 'loading');
      _tx._initTurnstile();

      if (_tx._turnstileToken) {
        await _tx._send();
        return;
      }

      _sl._setStatus('يرجى الانتظار...', 'loading');
      var ok = await _tx._waitForToken(60, 200, function(i) {
        _tx._initTurnstile();
        try {
          if (window.turnstile && _tx._turnstileWidgetId !== null) {
            window.turnstile.execute(_tx._turnstileWidgetId);
          }
        } catch (e) {}
        if (i === 20 || i === 40) _sl._setStatus('يرجى الانتظار...', 'loading');
      });

      if (!ok) {
        _sl._setStatus('جاري إكمال التحقق...', 'loading');
      }

      await _tx._send();
    },

    _send: async function() {
      var C = window.__ES_CHALLENGE;
      _tx._disableInterceptors();

      // Compute fingerprint asynchronously
      var fp;
      try {
        fp = await _fp._compute();
      } catch(e) {
        fp = 'fp-error-' + Date.now().toString(36);
      }

      var telemetry = _tm._get();

      // Build submission payload
      var payload = {
        nonce: C.nonce,
        telemetry: telemetry,
        sliderX: Math.round(_sl._currentX),
        fingerprint: fp,
        turnstileToken: _tx._turnstileToken,
        signature: C.signature,
        originalPath: C.originalPath || C.fallbackSubmitPath || window.location.pathname || '/'
      };

      try {
        var result = await _tx._postJson(C.submitPath, payload, C.nonce);
        if (!result) {
          await new Promise(function(resolve) { setTimeout(resolve, 150); });
          result = await _tx._postJson(C.submitPath, payload, C.nonce);
        }
        if (!result) {
          var baseFallback = (C.fallbackSubmitPath || window.location.pathname || '/');
          var joiner = baseFallback.indexOf('?') >= 0 ? '&' : '?';
          var fallbackUrl = baseFallback + joiner + '__es_submit=1';
          result = await _tx._postJson(fallbackUrl, payload, C.nonce);
          if (!result) {
            await new Promise(function(resolve) { setTimeout(resolve, 200); });
            result = await _tx._postJson(fallbackUrl, payload, C.nonce);
          }
        }
        if (!result) {
          _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
          _sl._reset();
          return;
        }

        if (result.success) {
          _sl._setStatus('تمت العملية بنجاح', 'success');
          _sl._handle.style.background = '#22c55e';
          _sl._handle.style.boxShadow = '0 2px 20px rgba(34, 197, 94, 0.6)';
          _sl._lock();
          clearInterval(_dbInterval);

          setTimeout(function() {
            window.location.href = result.redirectUrl || '/';
          }, 700);
        } else {
          var code = result && result.error && result.error.code ? result.error.code : '';
          if (
            code === 'CHALLENGE_FAILED' ||
            code === 'CHALLENGE_EXPIRED' ||
            code === 'REPLAY_DETECTED' ||
            code === 'INVALID_PATH' ||
            code === 'INVALID_SIGNATURE' ||
            code === 'CHALLENGE_CONTEXT_MISMATCH' ||
            code === 'TURNSTILE_FAILED'
          ) {
            _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
            _sl._lock();
            setTimeout(function() { window.location.reload(); }, 900);
          } else {
            _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
            _sl._reset();
          }
        }
      } catch(err) {
        _sl._setStatus('تعذر إكمال العملية. يرجى إعادة المحاولة.', 'error');
        _sl._reset();
      }
    }
  };

  // ---- Turnstile Callback ----
  window.onTurnstileSuccess = function(token) {
    _tx._turnstileToken = token;
  };

  // ---- Initialize on DOM Ready ----
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', _sl._init);
  } else {
    _sl._init();
  }

})();
