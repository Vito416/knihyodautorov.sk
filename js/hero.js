document.addEventListener("DOMContentLoaded", () => {
  // --- Citáty ---
  const quotes = [
    'Kniha je sen, ktorý držíš v ruke.',
    'Slová majú moc meniť svet.',
    'Čítanie je potravou pre dušu.',
    'Každá stránka skrýva nový príbeh.',
    'Kniha otvára dvere mysli.',
    'Čítaj a objavuj nové svety.',
    'Slová tvoria mosty medzi ľuďmi.',
    'Každá kniha je dobrodružstvo.',
    'Čítanie živí dušu a myseľ.',
    'Knihy šepkajú príbehy ticha.',
    'Stránky plné fantázie a snov.',
    'Čítanie je cesta k múdrosti.',
    'Kniha je priateľ na celý život.',
    'Slová majú silu meniť srdcia.',
    'Každý list skrýva nový svet.',
    'Kniha otvára nové obzory.',
    'Slová sú kľúč k srdcu.',
    'Čítanie rozvíja fantáziu a myseľ.',
    'Každá stránka prináša dobrodružstvo.',
    'Knihy sú mosty medzi svetmi.',
    'Čítanie je poklad duše.',
    'Slová tvoria svet okolo nás.',
    'Kniha je tichý spoločník.',
    'Čítaj a snívaj bez hraníc.',
    'Stránky plné múdrosti a snov.',
    'Každá kniha je cesta.',
    'Knihy rozprávajú príbehy života.',
    'Čítanie prebúdza myšlienky.',
    'Slová liečia a inšpirujú.',
    'Kniha je dar pre dušu.'
  ];

  const quoteElement = document.querySelector(".changing-quote");
  if (quoteElement) {
    let current = 0;
    const delay = 6000; // ms
    const fade = 500;   // ms

    function showNextQuote() {
      quoteElement.classList.add("fade-out");
      setTimeout(() => {
        current = (current + 1) % quotes.length;
        quoteElement.textContent = quotes[current];
        quoteElement.classList.remove("fade-out");
      }, fade);
    }

    setInterval(showNextQuote, delay);
  }

const wrapper = document.querySelector(".video-background");
const video = document.getElementById("video-background");

if (wrapper && video) {
  const MOBILE_MAX = 768;
  const desktopSrc = "/assets/backgroundheroinfinity.mp4";
  const mobileSrc = "/assets/backgroundmobile.mp4";
  const desktopPoster = "/assets/hero-fallback.png";
  const mobilePoster = "/assets/hero-mobile-fallback.png";

  const DEBUG = false;
  const BUFFER_BASE = 3;
  const BUFFER_IF_SLOW = 5;
  const BUFFER_IF_VERY_SLOW = 8;
  const BUFFER_STABLE_MS = 1800;   // slightly increased tolerance
  const BUFFER_CONSECUTIVE = 3;    // *** new: require 3 consecutive low-buffer checks before enabling fallback ***
  const MAX_RETRY_ATTEMPTS = 6;
  const MAX_BACKOFF_MS = 30000;

  const PAUSE_TOL_MS = 1500;
  const PAUSE_USER_THRESHOLD_MS = 700;
  const EMPTYED_IGNORE_MS = 700;

  let bufferIssueTimer = null;
  let playRetryTimer = null;
  let retryAttempts = 0;
  let forcedFallback = false;

  let pauseToleranceTimer = null;
  let lastLoadTime = 0;
  let lastSourceChangeTime = 0;
  let lastUserInteractionTime = 0;

  // *** new: consecutive low count to avoid single-failure fallback ***
  let consecutiveLowCount = 0;

  // --------- nastavení videa ----------
  try {
    video.muted = true;
    video.preload = "auto";
    video.playsInline = true;
  } catch (e) { /* ignore */ }

  // --------- pomocné logger funkce ----------
  function log(...args) { if (DEBUG) console.log("[video-bg]", ...args); }
  function dbg(...args) { if (DEBUG) console.debug("[video-bg]", ...args); }
  function warn(...args) { if (DEBUG) console.warn("[video-bg]", ...args); }
  function errorLog(...args) { if (DEBUG) console.error("[video-bg]", ...args); }

  // posun času uživatelské interakce (klik, touch, klávesa)
  function registerUserInteraction() {
    lastUserInteractionTime = Date.now();
    dbg(`[${new Date().toISOString()}] user interaction registered`);
  }
  ["click", "touchstart", "keydown"].forEach(e =>
    document.addEventListener(e, registerUserInteraction, { passive: true })
  );

  // --------- adaptivní buffer podle sítě ----------
  function getAdaptiveBuffer() {
    try {
      const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
      const downlink = conn?.downlink ?? null;
      const effective = conn?.effectiveType ?? null;
      dbg("NET INFO:", { downlink, effective });

      if (effective === "slow-2g" || effective === "2g") return BUFFER_IF_VERY_SLOW;
      if (downlink !== null) {
        if (downlink < 0.7) return BUFFER_IF_VERY_SLOW;
        if (downlink < 2) return BUFFER_IF_SLOW;
      }
    } catch (e) { dbg("getAdaptiveBuffer failed, using base", e); }
    return BUFFER_BASE;
  }

  // --------- buffer měření ----------
  function bufferedAhead() {
    try {
      const bt = video.buffered;
      const t = video.currentTime;
      for (let i = 0; i < bt.length; i++) {
        if (t >= bt.start(i) && t <= bt.end(i)) {
          return bt.end(i) - t;
        }
      }
      return 0;
    } catch (e) { return 0; }
  }

  // --------- buffer tolerantní logika (updated) ----------
  function onBufferShort() {
    // pokud už běží timeout, nebudeme zakládat další — počkáme až se vyhodnotí
    if (bufferIssueTimer) {
      dbg("onBufferShort called but timer already running");
      return;
    }

    const adapt = getAdaptiveBuffer();
    dbg(`[${new Date().toISOString()}] BUFFER SHORT detected -> starting tolerance timer`, { adapt, BUFFER_STABLE_MS, consecutiveLowCount });

    bufferIssueTimer = setTimeout(() => {
      bufferIssueTimer = null;
      const ahead = bufferedAhead();
      if (ahead < adapt) {
        // zvýšíme počet po sobě jdoucích neúspěšných kontrol
        consecutiveLowCount++;
        dbg(`[${new Date().toISOString()}] Buffer still low after tolerance (count ${consecutiveLowCount}/${BUFFER_CONSECUTIVE})`, { ahead, adapt });

        if (consecutiveLowCount >= BUFFER_CONSECUTIVE) {
          warn(`[${new Date().toISOString()}] Enabling fallback after ${consecutiveLowCount} consecutive low-buffer checks`, { ahead, adapt });
          enableFallback("buffer-low-after-consecutive-tolerance");
        } else {
          // ještě ne na fallback — necháme další šanci; další onBufferShort zavolá nový timer
          dbg(`[${new Date().toISOString()}] Not enough consecutive low checks yet — waiting for next one`, { consecutiveLowCount });
        }
      } else {
        // buffer se vrátil -> reset counter
        dbg(`[${new Date().toISOString()}] Buffer recovered during tolerance period`, { ahead, adapt });
        consecutiveLowCount = 0;
      }
    }, BUFFER_STABLE_MS);
  }

  function clearBufferIssue() {
    if (bufferIssueTimer) {
      clearTimeout(bufferIssueTimer);
      bufferIssueTimer = null;
      dbg("Cleared bufferIssueTimer");
    }
    // když se buffer obnoví, resetujeme i počet po sobě jdoucích selhání
    if (consecutiveLowCount !== 0) {
      dbg(`Resetting consecutiveLowCount from ${consecutiveLowCount} -> 0`);
      consecutiveLowCount = 0;
    }
  }

  // --------- fallback helpers ----------
  function enableFallback(reason = "unknown") {
    if (!forcedFallback) {
      forcedFallback = true;
      wrapper.classList.add("video-failed");
      warn(`[${new Date().toISOString()}] FALLBACK enabled:`, reason);
    } else {
      dbg("enableFallback called but already forced", reason);
    }
  }
  function disableFallback(reason = "manual") {
    if (forcedFallback) {
      forcedFallback = false;
      wrapper.classList.remove("video-failed");
      log(`[${new Date().toISOString()}] FALLBACK disabled:`, reason);
    } else {
      dbg("disableFallback called but forcedFallback already false", reason);
    }
  }

  // --------- zdroj + nastavení (měníme jen když se fakt změnilo) ----------
  function setSource() {
    const isMobile = window.innerWidth <= MOBILE_MAX;
    const relativeSrc = isMobile ? mobileSrc : desktopSrc;
    const relativePoster = isMobile ? mobilePoster : desktopPoster;
    const absoluteNewSrc = new URL(relativeSrc, location.href).href;
    const absoluteNewPoster = new URL(relativePoster, location.href).href;
    const srcChanged = (video.src !== absoluteNewSrc);
    const posterChanged = (video.poster !== absoluteNewPoster);

    if (!srcChanged && !posterChanged) {
      dbg(`[${new Date().toISOString()}] setSource called but no change detected -> skipping`);
      return;
    }

    dbg(`[${new Date().toISOString()}] setSource applying changes`, {
      srcChanged, posterChanged, absoluteNewSrc, absoluteNewPoster
    });

    if (srcChanged) {
      video.src = absoluteNewSrc;
      lastSourceChangeTime = Date.now();
    }
    if (posterChanged) video.poster = absoluteNewPoster;

    log(`[${new Date().toISOString()}] setSource applied`, { src: video.src, poster: video.poster });
  }

  setSource();
  window.addEventListener("resize", () => setSource());

  // --------- exponential backoff retry logic ----------
  function clearPlayRetry() {
    if (playRetryTimer) {
      clearTimeout(playRetryTimer);
      playRetryTimer = null;
      dbg("Cleared playRetryTimer");
    }
  }

  function scheduleRetry() {
    if (retryAttempts >= MAX_RETRY_ATTEMPTS) {
      dbg("Max retry attempts reached; not scheduling further retries");
      return;
    }
    retryAttempts++;
    let backoff = Math.min(1000 * Math.pow(2, retryAttempts - 1), MAX_BACKOFF_MS);
    backoff += Math.floor(Math.random() * 250);
    dbg("Scheduling retry", { retryAttempts, backoff });
    clearPlayRetry();
    playRetryTimer = setTimeout(() => {
      playRetryTimer = null;
      dbg("Retry timer fired - trying play (forceReload=true)", { retryAttempts });
      tryPlay(true);
    }, backoff);
  }

  function resetRetries() {
    retryAttempts = 0;
    clearPlayRetry();
    dbg("Retries reset");
  }

  // --------- robustní play (minimalizovat load()) ----------
  function tryPlay(forceReload = false) {
    if (forcedFallback && !forceReload) {
      dbg("Skipping tryPlay because forcedFallback is active and forceReload not requested");
      return;
    }

    let willLoad = false;

    if (forceReload) {
      try { video.pause(); } catch (e) { dbg("pause failed", e); }
      video.removeAttribute("src");
      setSource();
      dbg(`[${new Date().toISOString()}] Force reload requested - source reset`);
      willLoad = true;
    } else {
      if (video.readyState < 2) willLoad = true;
    }

    if (willLoad) {
      try {
        video.load();
        lastLoadTime = Date.now();
        dbg(`[${new Date().toISOString()}] video.load() called`);
      } catch (e) { dbg("video.load() failed", e); }
    } else {
      dbg(`[${new Date().toISOString()}] video.load() skipped (not necessary)`);
    }

    video.play()
      .then(() => {
        resetRetries();
        clearBufferIssue();
        clearPauseTolerance();
        disableFallback("play-success");
        dbg(`[${new Date().toISOString()}] play() succeeded`, { ahead: bufferedAhead(), consecutiveLowCount });
        const adapt = getAdaptiveBuffer();
        if (bufferedAhead() < adapt) onBufferShort();
      })
      .catch((err) => {
        errorLog(`[${new Date().toISOString()}] Video play failed:`, err);
        enableFallback("play-failed:" + (err && err.name ? err.name : String(err)));
        scheduleRetry();
      });
  }

  // --------- pause tolerantní logika ----------
  function clearPauseTolerance() {
    if (pauseToleranceTimer) {
      clearTimeout(pauseToleranceTimer);
      pauseToleranceTimer = null;
      dbg("Cleared pauseToleranceTimer");
    }
  }

  function startPauseTolerance() {
    clearPauseTolerance();
    dbg(`[${new Date().toISOString()}] pause tolerance started (will enable fallback if not resumed)`);
    pauseToleranceTimer = setTimeout(() => {
      pauseToleranceTimer = null;
      const sinceUser = Date.now() - lastUserInteractionTime;
      if (video.paused && !video.ended && sinceUser > PAUSE_USER_THRESHOLD_MS) {
        warn("Pause persisted beyond tolerance -> enabling fallback (pause-not-ended)", { sinceUser });
        enableFallback("paused-not-ended");
      } else {
        dbg("Pause resolved or user-initiated pause, no fallback", { sinceUser });
      }
    }, PAUSE_TOL_MS);
  }

  // --------- eventy a jejich zpracování ----------
  ["error", "abort"].forEach(evt =>
    video.addEventListener(evt, (e) => {
      errorLog(`[${new Date().toISOString()}] event:`, evt, e);
      enableFallback("event:" + evt);
    })
  );

  video.addEventListener("emptied", (e) => {
    const sinceLoad = Date.now() - lastLoadTime;
    const sinceSource = Date.now() - lastSourceChangeTime;
    dbg(`[${new Date().toISOString()}] event: emptied`, { sinceLoad, sinceSource });
    if (sinceLoad < EMPTYED_IGNORE_MS || sinceSource < EMPTYED_IGNORE_MS) {
      dbg(`[${new Date().toISOString()}] IGNORED emptied (recent load/source change)`, { sinceLoad, sinceSource });
      return;
    }
    errorLog(`[${new Date().toISOString()}] event: emptied (treated as critical)`, e);
    enableFallback("event:emptied");
  });

  video.addEventListener("waiting", (e) => {
    dbg(`[${new Date().toISOString()}] event: waiting`, { ahead: bufferedAhead(), consecutiveLowCount });
    onBufferShort();
  });

  video.addEventListener("suspend", (e) => {
    dbg(`[${new Date().toISOString()}] event: suspend (handled tolerantně)`, e);
  });

  video.addEventListener("progress", () => {
    dbg(`[${new Date().toISOString()}] event: progress`, { ahead: bufferedAhead(), consecutiveLowCount });
    if (bufferedAhead() >= getAdaptiveBuffer()) {
      clearBufferIssue();
      disableFallback("buffer-recovered-on-progress");
    }
  });

  video.addEventListener("timeupdate", () => {
    dbg(`[${new Date().toISOString()}] event: timeupdate`, { currentTime: video.currentTime, ahead: bufferedAhead(), forcedFallback, consecutiveLowCount });
    if (bufferedAhead() < getAdaptiveBuffer()) onBufferShort();
    else clearBufferIssue();
  });

  video.addEventListener("pause", () => {
    dbg(`[${new Date().toISOString()}] event: pause`, { currentTime: video.currentTime, ended: video.ended, readyState: video.readyState });
    const sinceUser = Date.now() - lastUserInteractionTime;
    if (sinceUser <= PAUSE_USER_THRESHOLD_MS) {
      dbg("Pause appears user-initiated -> ignoring for fallback", { sinceUser });
      return;
    }
    startPauseTolerance();
  });

  video.addEventListener("play", () => {
    dbg(`[${new Date().toISOString()}] event: play`, { ahead: bufferedAhead(), consecutiveLowCount });
    clearBufferIssue();
    clearPauseTolerance();
    disableFallback("play-event");
  });

  video.addEventListener("ended", () => {
    dbg(`[${new Date().toISOString()}] event: ended -> replay with forceReload`);
    tryPlay(true);
  });

  // kliknutí: jen když to dává smysl
  document.addEventListener("click", (ev) => {
    const needForce =
      forcedFallback ||
      video.paused ||
      playRetryTimer !== null ||
      (retryAttempts > 0 && retryAttempts < MAX_RETRY_ATTEMPTS);

    if (needForce) {
      dbg(`[${new Date().toISOString()}] User click -> performing force reload and tryPlay`, { forcedFallback, paused: video.paused, readyState: video.readyState, retryAttempts });
      clearPlayRetry();
      tryPlay(true);
    } else {
      dbg(`[${new Date().toISOString()}] User click ignored (video playing and no fallback/retry).`, { forcedFallback, paused: video.paused, readyState: video.readyState, retryAttempts });
    }
  });

  // cleanup
  window.addEventListener("beforeunload", () => {
    clearBufferIssue();
    clearPlayRetry();
    clearPauseTolerance();
  });

  // první pokus
  tryPlay();
}

});