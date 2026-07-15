<?php
/**
 * wallet_topup.php
 *
 * G-Cosh wallet top-up page - was wallet_topup.html, now a real PHP
 * page for one reason: it needs the SAME session dashboard.php already
 * establishes, so it can identify the account by $_SESSION['user_id']
 * instead of a client-editable ?uid= in the URL.
 *
 * SECURITY FIX (see wallet_topup_verify.php / wallet_topup_process.php
 * for the matching backend changes): the old flow trusted a bare uid
 * query parameter end-to-end - anyone could change the number in the
 * address bar and top up (or, worse, silently manipulate) a totally
 * different guest's wallet. This page now:
 *   1. Requires an active Haven Hotel session before rendering anything
 *      (same guard dashboard.php uses - no session, no page).
 *   2. Auto-detects the account from that session (the "automatic
 *      detect which account" requirement - now actually secure, since
 *      session IDs aren't guessable/editable the way a uid= is).
 *   3. Forces first-time MPIN creation if the account has none, then
 *      requires that MPIN on every visit before the top-up form
 *      unlocks (the "login section to confirm who's user" +
 *      "force to add MPIN" requirements).
 *
 * Opened via window.open() from the SAME origin (see dashboard.php's
 * proceedToTopup()), so the session cookie carries over automatically -
 * no token or handshake needed between the two pages.
 */

session_start();

require_once __DIR__ . '/includes/payment_wallet_engine.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title>G-Cosh — Add Money</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;600;700;800&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --gc-blue: #007DFE;
        --gc-blue-deep: #0037A8;
        --gc-blue-soft: #EAF3FF;
        --gc-ink: #12203B;
        --gc-bg: #F5F8FC;
        --gc-surface: #FFFFFF;
        --gc-muted: #6B7A94;
        --gc-line: #E4EBF5;
        --gc-success: #00B14F;
        --gc-success-soft: #E4F9EC;
        --gc-danger: #EB5757;
        --gc-danger-soft: #FDECEC;
        --gc-radius-lg: 22px;
        --gc-radius-md: 14px;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
        background: var(--gc-bg);
        font-family: 'Inter', sans-serif;
        color: var(--gc-ink);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 32px 24px;
        overflow-x: hidden;
    }
    .gc-shell {
        width: 100%; max-width: 880px; min-height: 500px;
        display: flex; flex-direction: column; position: relative;
        background: var(--gc-surface); border-radius: 28px; overflow: hidden;
        box-shadow: 0 30px 70px -24px rgba(18,32,59,0.28), 0 1px 2px rgba(18,32,59,0.04);
    }

    /* ============================================================
       SPLASH SCREEN - the requested "loading screen and animation".
       Full-bleed brand moment before anything else renders, echoing
       the branded splash real banking apps show on cold open.
       ============================================================ */
    .gc-splash {
        position: fixed; inset: 0; background: linear-gradient(160deg, var(--gc-blue) 0%, var(--gc-blue-deep) 100%);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        z-index: 1000; transition: opacity 0.45s ease, visibility 0.45s ease;
    }
    .gc-splash.gc-splash-hidden { opacity: 0; visibility: hidden; pointer-events: none; }
    .gc-splash-mark {
        width: 88px; height: 88px; border-radius: 26px; background: white;
        display: flex; align-items: center; justify-content: center;
        animation: gc-splash-pulse 1.4s ease-in-out infinite;
        box-shadow: 0 20px 45px -15px rgba(0,20,60,0.45);
    }
    .gc-splash-mark img {
        width: 42px;
        height: 42px;
        object-fit: contain;
    }
    @keyframes gc-splash-pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.08); }
    }
    .gc-splash-word {
        font-family: 'Baloo 2', sans-serif; font-weight: 800; font-size: 26px; color: white;
        margin-top: 22px; letter-spacing: 0.2px;
    }
    .gc-splash-tag { font-size: 12px; color: rgba(255,255,255,0.75); margin-top: 6px; font-weight: 500; }
    .gc-splash-bar-track { width: 140px; height: 4px; background: rgba(255,255,255,0.25); border-radius: 4px; margin-top: 28px; overflow: hidden; }
    .gc-splash-bar-fill { height: 100%; width: 40%; background: white; border-radius: 4px; animation: gc-splash-load 1.1s ease-in-out infinite; }
    @keyframes gc-splash-load {
        0% { transform: translateX(-100%); }
        100% { transform: translateX(350%); }
    }

    /* ============================================================
       Top brand bar (persistent header once past splash)
       ============================================================ */
    .gc-topbar {
        background: linear-gradient(135deg, var(--gc-blue) 0%, var(--gc-blue-deep) 100%);
        padding: 24px 40px; position: relative; flex-shrink: 0;
    }
    .gc-topbar-row { display: flex; align-items: center; justify-content: space-between; }
    .gc-wordmark {
        font-family: 'Baloo 2', sans-serif; font-weight: 800; font-size: 21px;
        color: white; letter-spacing: 0.2px; display: inline-flex; align-items: center; gap: 8px;
    }
    .gc-wordmark-mark {
        width: 30px; height: 30px; border-radius: 9px; background: white;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .gc-wordmark-mark img {
        width: 16px;
        height: 16px;
        object-fit: contain;
    }
    .gc-demo-chip {
        display: inline-flex; align-items: center; gap: 6px;
        background: rgba(255,255,255,0.18); color: white;
        font-size: 10.5px; font-weight: 700; padding: 5px 11px; border-radius: 999px;
        text-transform: uppercase; letter-spacing: 0.4px;
    }
    .gc-demo-chip span.dot { width: 5px; height: 5px; border-radius: 50%; background: white; }
    .gc-topbar-sub { font-size: 11.5px; color: rgba(255,255,255,0.7); margin-top: 10px; line-height: 1.6; max-width: 620px; }

    /* Wide landscape content well - one state-card visible at a time,
       centered vertically so the panel feels stable across states
       instead of top-anchored like the old portrait layout. */
    .gc-body { flex: 1; padding: 36px 40px 32px; display: flex; flex-direction: column; justify-content: center; }

    .gc-card {
        background: var(--gc-surface); border-radius: var(--gc-radius-lg);
        padding: 4px 2px; margin-bottom: 0;
    }

    /* ---- Skeleton loading (verify step) ---- */
    .gc-skel { background: linear-gradient(90deg, #EEF2F8 25%, #E4EAF3 37%, #EEF2F8 63%); background-size: 400% 100%; animation: gc-skel-shine 1.4s ease infinite; border-radius: 8px; }
    @keyframes gc-skel-shine { 0% { background-position: 100% 50%; } 100% { background-position: 0 50%; } }
    .gc-skel-row { display: flex; align-items: center; gap: 12px; }
    .gc-skel-avatar { width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0; }
    .gc-skel-lines { flex: 1; }
    .gc-skel-line { height: 10px; margin-bottom: 8px; }
    .gc-skel-line:last-child { margin-bottom: 0; width: 60%; }

    /* ---- Account-detected row ---- */
    .gc-account-row { display: flex; align-items: center; gap: 12px; padding-bottom: 20px; margin-bottom: 20px; border-bottom: 1px solid var(--gc-line); }
    .gc-account-avatar {
        width: 44px; height: 44px; border-radius: 50%; background: var(--gc-blue-deep); color: white;
        display: flex; align-items: center; justify-content: center; font-family: 'Baloo 2', sans-serif;
        font-weight: 700; font-size: 16px; flex-shrink: 0;
    }
    .gc-account-info { flex: 1; min-width: 0; }
    .gc-account-label { font-size: 10.5px; color: var(--gc-muted); text-transform: uppercase; font-weight: 700; letter-spacing: 0.5px; display: block; }
    .gc-account-name { font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 16px; margin-top: 2px; }
    .gc-account-status-icon { color: var(--gc-success); font-size: 19px; flex-shrink: 0; }

    /* ---- Landscape form layout: account + quick amounts on the left,
       the amount hero on the right ---- */
    .gc-form-grid { display: grid; grid-template-columns: 240px 1fr; gap: 44px; align-items: center; }
    .gc-form-col-left { display: flex; flex-direction: column; }
    .gc-form-col-right { display: flex; flex-direction: column; padding-left: 44px; border-left: 1px solid var(--gc-line); }

    /* ---- Amount hero (kept as signature element) ---- */
    .gc-amount-label { font-size: 12.5px; color: var(--gc-muted); font-weight: 600; text-align: center; margin-bottom: 6px; }
    .gc-amount-wrap { display: flex; align-items: baseline; justify-content: center; gap: 4px; min-height: 84px; }
    .gc-peso-sign { font-family: 'Baloo 2', sans-serif; font-weight: 600; color: var(--gc-muted); font-size: 28px; transition: font-size 0.15s ease; }
    .gc-amount-input {
        font-family: 'Baloo 2', sans-serif; font-weight: 700; color: var(--gc-ink);
        border: none; background: transparent; outline: none; text-align: center;
        font-size: 56px; width: 100%; max-width: 260px; font-variant-numeric: tabular-nums;
        transition: font-size 0.15s ease;
    }
    .gc-amount-input::placeholder { color: #C7D2E3; }
    .gc-quick-amounts { display: flex; flex-direction: column; gap: 8px; margin-top: 22px; }
    .gc-quick-chip {
        width: 100%; background: var(--gc-bg); border: 1px solid var(--gc-line); color: var(--gc-ink);
        font-size: 13px; font-weight: 600; padding: 10px 16px; border-radius: 999px; cursor: pointer;
        transition: all 0.15s ease;
    }
    .gc-quick-chip:hover { border-color: var(--gc-blue); color: var(--gc-blue); background: var(--gc-blue-soft); }

    .gc-btn {
        width: 100%; padding: 16px; border-radius: var(--gc-radius-md); border: none;
        font-family: 'Inter', sans-serif; font-weight: 700; font-size: 15px; cursor: pointer;
        display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: all 0.15s ease;
    }
    .gc-btn-primary { background: var(--gc-blue); color: white; }
    .gc-btn-primary:hover:not(:disabled) { background: var(--gc-blue-deep); }
    .gc-btn-primary:disabled { background: #D7E2F3; color: #9CACC7; cursor: not-allowed; }
    .gc-btn-ghost { background: transparent; color: var(--gc-muted); border: 1px solid var(--gc-line); }
    .gc-btn-ghost:hover { border-color: var(--gc-muted); }
    .gc-btn-text { background: none; border: none; color: var(--gc-blue); font-weight: 700; font-size: 13.5px; cursor: pointer; padding: 8px; }
    .gc-btn-text:hover { text-decoration: underline; }

    .gc-status-banner {
        display: flex; align-items: flex-start; gap: 10px; padding: 14px 16px; border-radius: var(--gc-radius-md);
        font-size: 13px; line-height: 1.5; margin-bottom: 16px;
    }
    .gc-status-banner.gc-status-error { background: var(--gc-danger-soft); color: #B93A3A; }
    .gc-status-banner.gc-status-info { background: var(--gc-blue-soft); color: var(--gc-blue-deep); }

    /* ============================================================
       MPIN keypad - custom on-screen numeric pad + dot indicators,
       matching how GCash's own MPIN entry looks and feels rather
       than a plain browser text input.
       ============================================================ */
    .gc-mpin-grid { display: grid; grid-template-columns: 1fr 300px; gap: 44px; align-items: center; }
    .gc-mpin-col-left { display: flex; flex-direction: column; }
    .gc-mpin-col-right { display: flex; flex-direction: column; align-items: center; padding-left: 44px; border-left: 1px solid var(--gc-line); }
    .gc-mpin-title { font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 19px; text-align: left; margin-bottom: 6px; }
    .gc-mpin-sub { font-size: 13px; color: var(--gc-muted); text-align: left; margin-bottom: 18px; line-height: 1.5; }
    .gc-mpin-dots { display: flex; justify-content: center; gap: 14px; margin-bottom: 30px; }
    .gc-mpin-dot { width: 16px; height: 16px; border-radius: 50%; border: 2px solid var(--gc-line); transition: all 0.15s ease; }
    .gc-mpin-dot.gc-mpin-dot-filled { background: var(--gc-blue); border-color: var(--gc-blue); }
    .gc-mpin-dot.gc-mpin-dot-error { background: var(--gc-danger); border-color: var(--gc-danger); animation: gc-mpin-shake 0.4s ease; }
    @keyframes gc-mpin-shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-5px); }
        75% { transform: translateX(5px); }
    }
    .gc-keypad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; max-width: 280px; margin: 0 auto; }
    .gc-key {
        aspect-ratio: 1; border-radius: 50%; border: none; background: var(--gc-bg);
        font-family: 'Baloo 2', sans-serif; font-size: 22px; font-weight: 700; color: var(--gc-ink);
        cursor: pointer; transition: all 0.12s ease; display: flex; align-items: center; justify-content: center;
    }
    .gc-key:hover { background: var(--gc-blue-soft); color: var(--gc-blue); }
    .gc-key:active { transform: scale(0.92); }
    .gc-key-empty { visibility: hidden; }
    .gc-key-back { font-size: 16px; }

    /* Confirmation modal (was a mobile bottom sheet; centered dialog
       suits the wide landscape panel instead of a full-width sheet) */
    .gc-sheet-backdrop {
        position: fixed; inset: 0; background: rgba(18,32,59,0.5);
        display: none; align-items: center; justify-content: center; z-index: 100;
        padding: 24px;
    }
    .gc-sheet-backdrop.gc-sheet-open { display: flex; }
    .gc-sheet {
        background: white; width: 100%; max-width: 400px; border-radius: 24px;
        padding: 30px 26px 26px; transform: translateY(18px) scale(0.97); opacity: 0;
        transition: transform 0.28s cubic-bezier(0.32, 0.72, 0, 1), opacity 0.22s ease;
        box-shadow: 0 24px 60px -14px rgba(18,32,59,0.35);
    }
    .gc-sheet-backdrop.gc-sheet-open .gc-sheet { transform: translateY(0) scale(1); opacity: 1; }
    .gc-sheet-handle { display: none; }
    .gc-sheet-title { font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 18px; text-align: center; margin-bottom: 8px; }
    .gc-sheet-question { font-size: 14px; color: var(--gc-muted); text-align: center; margin-bottom: 22px; line-height: 1.5; }
    .gc-sheet-amount-preview {
        font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 32px; text-align: center;
        color: var(--gc-blue-deep); margin-bottom: 24px;
    }
    .gc-sheet-actions { display: flex; gap: 10px; }

    /* Success state */
    .gc-success-wrap { max-width: 380px; margin: 0 auto; }
    .gc-success-icon {
        width: 68px; height: 68px; border-radius: 50%; background: var(--gc-success-soft); color: var(--gc-success);
        display: flex; align-items: center; justify-content: center; font-size: 30px; margin: 0 auto 18px;
        animation: gc-success-pop 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    @keyframes gc-success-pop {
        0% { transform: scale(0); }
        100% { transform: scale(1); }
    }
    .gc-success-title { font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 19px; text-align: center; margin-bottom: 8px; }
    .gc-success-detail { font-size: 13.5px; color: var(--gc-muted); text-align: center; line-height: 1.6; margin-bottom: 6px; }
    .gc-success-balance { font-family: 'Baloo 2', sans-serif; font-weight: 700; font-size: 26px; text-align: center; color: var(--gc-ink); margin: 16px 0 22px; }

    .gc-footer {
        text-align: center; font-size: 10.5px; color: #9CACC7; line-height: 1.7; padding: 8px 10px 0;
        max-width: 640px; margin-left: auto; margin-right: auto;
    }

    .gc-spin { animation: gc-spin-anim 0.8s linear infinite; display: inline-block; }
    @keyframes gc-spin-anim { to { transform: rotate(360deg); } }

    @media (prefers-reduced-motion: reduce) {
        .gc-sheet, .gc-spin, .gc-amount-input, .gc-peso-sign, .gc-splash-mark, .gc-splash-bar-fill, .gc-success-icon, .gc-mpin-dot, .gc-skel {
            transition: none; animation: none;
        }
    }

    /* Landscape is the primary design; collapse gracefully back to a
       single column if the viewport genuinely isn't wide enough for it. */
    @media (max-width: 720px) {
        body { padding: 0; align-items: stretch; }
        .gc-shell { border-radius: 0; min-height: 100vh; }
        .gc-form-grid, .gc-mpin-grid { grid-template-columns: 1fr; gap: 26px; }
        .gc-form-col-right, .gc-mpin-col-right { padding-left: 0; border-left: none; }
        .gc-quick-amounts { flex-direction: row; flex-wrap: wrap; }
        .gc-quick-chip { width: auto; }
    }

    @media (max-width: 380px) {
        .gc-amount-input { font-size: 46px; }
        .gc-keypad { max-width: 240px; }
    }
</style>
</head>
<body>

<!-- SPLASH: the requested "loading screen and animation" -->
<div class="gc-splash" id="gc_splash">
    <div class="gc-splash-mark">
        <img src="assets/gcosh.png" style="width: 80px; height: auto; border-radius: 12px;" alt="G-Cosh Logo">
    </div>
    <div class="gc-splash-word">G-Cosh</div>
    <div class="gc-splash-tag">Simulated wallet top-up</div>
    <div class="gc-splash-bar-track"><div class="gc-splash-bar-fill"></div></div>
</div>

<div class="gc-shell">
    <div class="gc-topbar">
        <div class="gc-topbar-row">
            <div class="gc-wordmark">
                <div class="gc-wordmark-mark">
                    <img src="assets/gcosh.png" style="width: 30px; height: 40px; border-radius: 12px;" alt="G-Cosh Logo">
                </div>
                G-Cosh
            </div>
            <div class="gc-demo-chip"><span class="dot"></span> Simulation</div>
        </div>
        <p class="gc-topbar-sub">Haven Hotel Wallet ("G-Cosh") adds balance to your Haven Hotel account. It is not GCash, and is not affiliated with, endorsed by, or connected to Globe Fintech Innovations, Inc. ("GCash").</p>
    </div>

    <div class="gc-body">

        <!-- Verify / skeleton-loading state -->
        <div class="gc-card" id="gc_verify_card">
            <div class="gc-skel-row" style="max-width:380px; margin:0 auto;">
                <div class="gc-skel gc-skel-avatar"></div>
                <div class="gc-skel-lines">
                    <div class="gc-skel gc-skel-line" style="width:45%;"></div>
                    <div class="gc-skel gc-skel-line"></div>
                </div>
            </div>
        </div>

        <!-- Error state -->
        <div class="gc-card" id="gc_error_card" style="display:none; max-width:420px; margin:0 auto;">
            <div class="gc-status-banner gc-status-error">
                <span>⚠</span>
                <span id="gc_error_message">Something went wrong.</span>
            </div>
            <p style="font-size:12.5px; color:var(--gc-muted); text-align:center; line-height:1.6;">
                Go back to your Haven Hotel dashboard and tap <strong>Add Money</strong> again.
            </p>
        </div>

        <!-- MPIN SETUP: forced first-time creation before login/top-up
             is possible at all (the "if detected user doesn't have MPIN"
             requirement). Two internal steps: create, then confirm. -->
        <div class="gc-card" id="gc_mpin_setup_card" style="display:none;">
            <div class="gc-mpin-grid">
                <div class="gc-mpin-col-left">
                    <div class="gc-mpin-title" id="gc_mpin_setup_title">Create your MPIN</div>
                    <p class="gc-mpin-sub" id="gc_mpin_setup_sub">You don't have an MPIN yet. Set a 6-digit MPIN to confirm it's really you before adding money.</p>
                    <div class="gc-status-banner gc-status-error" id="gc_mpin_setup_error" style="display:none;"></div>
                </div>
                <div class="gc-mpin-col-right">
                    <div class="gc-mpin-dots" id="gc_mpin_setup_dots">
                        <div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div>
                        <div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div>
                    </div>
                    <div class="gc-keypad" id="gc_mpin_setup_keypad"></div>
                </div>
            </div>
        </div>

        <!-- MPIN ENTRY: the real "login section to confirm who's user"
             gate for accounts that already have an MPIN. This runs
             against $_SESSION['user_id'] server-side, so it's an actual
             identity check, not cosmetic. -->
        <div class="gc-card" id="gc_mpin_entry_card" style="display:none;">
            <div class="gc-mpin-grid">
                <div class="gc-mpin-col-left">
                    <div class="gc-account-row" id="gc_mpin_entry_account_row">
                        <div class="gc-account-avatar" id="gc_mpin_account_initial">?</div>
                        <div class="gc-account-info">
                            <span class="gc-account-label">Confirm it's you</span>
                            <div class="gc-account-name" id="gc_mpin_account_name">—</div>
                        </div>
                    </div>
                    <div class="gc-mpin-title">Enter your MPIN</div>
                    <p class="gc-mpin-sub">Confirm your MPIN to continue.</p>
                    <div class="gc-status-banner gc-status-error" id="gc_mpin_entry_error" style="display:none;"></div>
                </div>
                <div class="gc-mpin-col-right">
                    <div class="gc-mpin-dots" id="gc_mpin_entry_dots">
                        <div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div>
                        <div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div><div class="gc-mpin-dot"></div>
                    </div>
                    <div class="gc-keypad" id="gc_mpin_entry_keypad"></div>
                </div>
            </div>
        </div>

        <!-- Main top-up form (unlocked only after MPIN confirms identity) -->
        <div class="gc-card" id="gc_form_card" style="display:none;">
            <div class="gc-form-grid">
                <div class="gc-form-col-left">
                    <div class="gc-account-row">
                        <div class="gc-account-avatar" id="gc_account_initial">?</div>
                        <div class="gc-account-info">
                            <span class="gc-account-label">Adding money to</span>
                            <div class="gc-account-name" id="gc_account_name">—</div>
                        </div>
                        <div class="gc-account-status-icon">✓</div>
                    </div>
                    <div class="gc-quick-amounts">
                        <button type="button" class="gc-quick-chip" onclick="gcSetAmount(500)">₱500</button>
                        <button type="button" class="gc-quick-chip" onclick="gcSetAmount(1000)">₱1,000</button>
                        <button type="button" class="gc-quick-chip" onclick="gcSetAmount(2500)">₱2,500</button>
                        <button type="button" class="gc-quick-chip" onclick="gcSetAmount(5000)">₱5,000</button>
                    </div>
                </div>
                <div class="gc-form-col-right">
                    <div class="gc-amount-label">How much would you like to add?</div>
                    <div class="gc-amount-wrap">
                        <span class="gc-peso-sign" id="gc_peso_sign">₱</span>
                        <input type="text" inputmode="decimal" class="gc-amount-input" id="gc_amount_input" placeholder="0" autocomplete="off">
                    </div>
                </div>
            </div>
        </div>

        <button type="button" class="gc-btn gc-btn-primary" id="gc_confirm_btn" style="display:none;" disabled onclick="gcOpenSheet()">
            Add Money
        </button>

        <!-- Success overlay -->
        <div class="gc-card" id="gc_success_card" style="display:none; text-align:center;">
            <div class="gc-success-wrap">
                <div class="gc-success-icon">✓</div>
                <div class="gc-success-title">Money Added</div>
                <div class="gc-success-detail">Your Haven Hotel wallet has been topped up successfully.</div>
                <div class="gc-success-balance" id="gc_success_new_balance">₱0.00</div>
                <p style="font-size:12px; color:var(--gc-muted);" id="gc_success_close_note">You can close this tab and return to your Haven Hotel dashboard.</p>
            </div>
        </div>

        <p class="gc-footer" style="font-weight:700; color:var(--gc-ink); margin-bottom:8px;">
            Haven Hotel Wallet is not GCash and is not affiliated with, endorsed by, or connected to Globe Fintech Innovations, Inc.
        </p>
        <p class="gc-footer">
            © 2026 All Rights Reserved. This application is the original work of its owner. Any unauthorized cloning, copying, reverse engineering, distribution, or reproduction of this application is strictly prohibited and may result in legal action.
        </p>
    </div>
</div>

<!-- Confirmation modal -->
<div class="gc-sheet-backdrop" id="gc_confirm_sheet" onclick="if(event.target===this) gcCloseSheet()">
    <div class="gc-sheet">
        <div class="gc-sheet-handle"></div>
        <div class="gc-sheet-title">Confirm Top-Up</div>
        <div class="gc-sheet-question">Are you sure this amount will be added to your account?</div>
        <div class="gc-sheet-amount-preview" id="gc_sheet_amount_preview">₱0</div>
        <div class="gc-sheet-actions">
            <button type="button" class="gc-btn gc-btn-ghost" onclick="gcCloseSheet()">No</button>
            <button type="button" class="gc-btn gc-btn-primary" id="gc_sheet_yes_btn" onclick="gcSubmitTopup()">Yes, Add It</button>
        </div>
    </div>
</div>

<script>
(function () {
    // ---- Element refs ----
    const splash = document.getElementById('gc_splash');
    const verifyCard = document.getElementById('gc_verify_card');
    const errorCard = document.getElementById('gc_error_card');
    const errorMessage = document.getElementById('gc_error_message');
    const mpinSetupCard = document.getElementById('gc_mpin_setup_card');
    const mpinEntryCard = document.getElementById('gc_mpin_entry_card');
    const formCard = document.getElementById('gc_form_card');
    const confirmBtn = document.getElementById('gc_confirm_btn');
    const successCard = document.getElementById('gc_success_card');

    const mpinAccountInitial = document.getElementById('gc_mpin_account_initial');
    const mpinAccountName = document.getElementById('gc_mpin_account_name');
    const accountInitial = document.getElementById('gc_account_initial');
    const accountName = document.getElementById('gc_account_name');
    const amountInput = document.getElementById('gc_amount_input');
    const pesoSign = document.getElementById('gc_peso_sign');

    const sheet = document.getElementById('gc_confirm_sheet');
    const sheetAmountPreview = document.getElementById('gc_sheet_amount_preview');
    const sheetYesBtn = document.getElementById('gc_sheet_yes_btn');

    let maskedName = '';
    let confirmedMpin = ''; // held in memory only long enough to submit the top-up itself

    function showOnly(cardToShow) {
        [verifyCard, errorCard, mpinSetupCard, mpinEntryCard, formCard, confirmBtn, successCard].forEach(el => { el.style.display = 'none'; });
        if (cardToShow) cardToShow.style.display = (cardToShow === confirmBtn) ? 'flex' : 'block';
    }

    function formatPeso(n) {
        return '₱' + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    // ================================================================
    // SPLASH: minimum-duration branded loading screen. Doesn't gate on
    // the verify fetch finishing (that has its own skeleton state right
    // after) - this is purely the app-open moment the brief asked for.
    // ================================================================
    function hideSplash() {
        splash.classList.add('gc-splash-hidden');
    }
    const SPLASH_MIN_MS = 900;
    const splashStart = Date.now();

    // ================================================================
    // Auto-close after a successful top-up. window.close() only works on
    // tabs this page itself opened via window.open() - a browser security
    // rule, not something this code can override.
    // ================================================================
    function startAutoCloseCountdown() {
        const closeNote = document.getElementById('gc_success_close_note');
        if (!window.opener || window.opener.closed) {
            return;
        }
        let secondsLeft = 3;
        closeNote.innerText = 'This tab will close automatically in ' + secondsLeft + '...';
        const timer = setInterval(function () {
            secondsLeft -= 1;
            if (secondsLeft <= 0) {
                clearInterval(timer);
                window.close();
                closeNote.innerText = 'You can close this tab and return to your Haven Hotel dashboard.';
            } else {
                closeNote.innerText = 'This tab will close automatically in ' + secondsLeft + '...';
            }
        }, 1000);
    }

    // Auto-sizing amount text - shrinks as the number grows.
    function resizeAmountText() {
        const len = amountInput.value.length;
        let fontSize = 56;
        if (len > 4) fontSize = 46;
        if (len > 6) fontSize = 36;
        if (len > 8) fontSize = 28;
        amountInput.style.fontSize = fontSize + 'px';
        pesoSign.style.fontSize = Math.max(20, fontSize * 0.5) + 'px';
    }

    window.gcSetAmount = function (val) {
        amountInput.value = val;
        resizeAmountText();
        updateConfirmState();
    };

    function currentAmount() {
        const raw = amountInput.value.replace(/,/g, '');
        const n = parseFloat(raw);
        return isNaN(n) ? 0 : n;
    }

    function updateConfirmState() {
        const amt = currentAmount();
        confirmBtn.disabled = !(amt > 0);
    }

    amountInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^0-9.]/g, '');
        resizeAmountText();
        updateConfirmState();
    });

    window.gcOpenSheet = function () {
        const amt = currentAmount();
        if (amt <= 0) return;
        sheetAmountPreview.innerText = formatPeso(amt);
        sheet.classList.add('gc-sheet-open');
    };

    window.gcCloseSheet = function () {
        sheet.classList.remove('gc-sheet-open');
    };

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && sheet.classList.contains('gc-sheet-open')) gcCloseSheet();
    });

    window.gcSubmitTopup = function () {
        const amt = currentAmount();
        sheetYesBtn.disabled = true;
        sheetYesBtn.innerHTML = '<span class="gc-spin">⟳</span> Processing...';

        const body = new URLSearchParams();
        body.set('amount', amt);
        body.set('mpin', confirmedMpin);

        fetch('wallet_topup_process.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(res => res.json())
        .then(data => {
            gcCloseSheet();
            if (data.success) {
                document.getElementById('gc_success_new_balance').innerText = 'New balance: ' + formatPeso(data.new_balance);
                showOnly(successCard);
                startAutoCloseCountdown();
            } else {
                errorMessage.innerText = data.error || 'Something went wrong while adding money. Please try again.';
                showOnly(errorCard);
            }
        })
        .catch(function () {
            gcCloseSheet();
            errorMessage.innerText = 'Could not reach G-Cosh right now. Please check your connection and try again.';
            showOnly(errorCard);
        })
        .finally(() => {
            sheetYesBtn.disabled = false;
            sheetYesBtn.innerText = 'Yes, Add It';
        });
    };

    // ================================================================
    // MPIN keypad builder - shared between the setup and entry cards.
    // Renders a 1-9, blank, 0, backspace grid and wires taps to a
    // caller-supplied onDigit/onBackspace pair, matching the on-screen
    // numeric pad pattern GCash itself uses for MPIN entry.
    // ================================================================
    function buildKeypad(container, onDigit, onBackspace) {
        container.innerHTML = '';
        const keys = ['1','2','3','4','5','6','7','8','9','','0','back'];
        keys.forEach(k => {
            const btn = document.createElement('button');
            btn.type = 'button';
            if (k === '') {
                btn.className = 'gc-key gc-key-empty';
                btn.disabled = true;
            } else if (k === 'back') {
                btn.className = 'gc-key gc-key-back';
                btn.innerHTML = '⌫';
                btn.setAttribute('aria-label', 'Backspace');
                btn.onclick = onBackspace;
            } else {
                btn.className = 'gc-key';
                btn.innerText = k;
                btn.onclick = function () { onDigit(k); };
            }
            container.appendChild(btn);
        });
    }

    function renderDots(dotsContainer, length, errorState) {
        const dots = dotsContainer.querySelectorAll('.gc-mpin-dot');
        dots.forEach((dot, i) => {
            dot.classList.toggle('gc-mpin-dot-filled', i < length);
            dot.classList.toggle('gc-mpin-dot-error', !!errorState);
        });
    }

    // ================================================================
    // MPIN SETUP flow (forced first-time creation). Two internal steps
    // held in this closure: 'create' then 'confirm'. Both must match
    // before wallet_topup_mpin_setup.php is called.
    // ================================================================
    (function setupMpinFlow() {
        const dotsEl = document.getElementById('gc_mpin_setup_dots');
        const titleEl = document.getElementById('gc_mpin_setup_title');
        const subEl = document.getElementById('gc_mpin_setup_sub');
        const errEl = document.getElementById('gc_mpin_setup_error');
        const keypadEl = document.getElementById('gc_mpin_setup_keypad');

        let step = 'create'; // 'create' -> 'confirm'
        let firstEntry = '';
        let current = '';

        function reset(toStep) {
            step = toStep;
            current = '';
            errEl.style.display = 'none';
            renderDots(dotsEl, 0, false);
            if (step === 'create') {
                titleEl.innerText = 'Create your MPIN';
                subEl.innerText = "You don't have an MPIN yet. Set a 6-digit MPIN to confirm it's really you before adding money.";
            } else {
                titleEl.innerText = 'Confirm your MPIN';
                subEl.innerText = 'Enter the same 6-digit MPIN again to confirm.';
            }
        }

        function onDigit(d) {
            if (current.length >= 6) return;
            current += d;
            renderDots(dotsEl, current.length, false);
            if (current.length === 6) {
                setTimeout(handleComplete, 150);
            }
        }

        function onBackspace() {
            current = current.slice(0, -1);
            renderDots(dotsEl, current.length, false);
        }

        function shakeError(msg) {
            errEl.innerText = msg;
            errEl.style.display = 'flex';
            renderDots(dotsEl, 6, true);
            setTimeout(function () {
                current = '';
                renderDots(dotsEl, 0, false);
            }, 420);
        }

        function handleComplete() {
            if (step === 'create') {
                firstEntry = current;
                reset('confirm');
                return;
            }
            // step === 'confirm'
            if (current !== firstEntry) {
                shakeError('MPINs do not match. Please start again.');
                setTimeout(function () { reset('create'); }, 500);
                return;
            }

            const body = new URLSearchParams();
            body.set('mpin', firstEntry);
            body.set('mpin_confirm', current);

            fetch('wallet_topup_mpin_setup.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    confirmedMpin = firstEntry;
                    unlockForm();
                } else {
                    shakeError(data.error || 'Could not save your MPIN. Please try again.');
                    setTimeout(function () { reset('create'); }, 500);
                }
            })
            .catch(function () {
                shakeError('Could not reach G-Cosh right now.');
                setTimeout(function () { reset('create'); }, 500);
            });
        }

        buildKeypad(keypadEl, onDigit, onBackspace);
        window.gcStartMpinSetup = function () { reset('create'); showOnly(mpinSetupCard); };
    })();

    // ================================================================
    // MPIN ENTRY flow (the "confirm who's user" login gate for
    // accounts that already have an MPIN). Verified server-side inside
    // wallet_topup_process.php on actual submission - this call is a
    // convenience check so the guest gets fast feedback on a wrong MPIN
    // without having to fill in an amount first.
    // ================================================================
    (function entryMpinFlow() {
        const dotsEl = document.getElementById('gc_mpin_entry_dots');
        const errEl = document.getElementById('gc_mpin_entry_error');
        const keypadEl = document.getElementById('gc_mpin_entry_keypad');
        let current = '';

        function reset() {
            current = '';
            errEl.style.display = 'none';
            renderDots(dotsEl, 0, false);
        }

        function onDigit(d) {
            if (current.length >= 6) return;
            current += d;
            renderDots(dotsEl, current.length, false);
            if (current.length === 6) {
                setTimeout(handleComplete, 150);
            }
        }

        function onBackspace() {
            current = current.slice(0, -1);
            renderDots(dotsEl, current.length, false);
        }

        function handleComplete() {
            // verify_only=1 checks the MPIN against the session's account
            // without touching the wallet, so this gate gives immediate
            // feedback on a wrong MPIN before the guest has even picked
            // an amount. The ACTUAL top-up submission re-verifies the
            // MPIN again server-side regardless (see
            // wallet_topup_process.php) - this call is purely for a fast,
            // correct-feeling UI, not the authoritative check.
            const body = new URLSearchParams();
            body.set('verify_only', '1');
            body.set('mpin', current);

            fetch('wallet_topup_process.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    confirmedMpin = current;
                    unlockForm();
                } else {
                    errEl.innerText = data.error || 'Incorrect MPIN. Please try again.';
                    errEl.style.display = 'flex';
                    renderDots(dotsEl, 6, true);
                    setTimeout(reset, 420);
                }
            })
            .catch(function () {
                errEl.innerText = 'Could not reach G-Cosh right now.';
                errEl.style.display = 'flex';
                setTimeout(reset, 420);
            });
        }

        buildKeypad(keypadEl, onDigit, onBackspace);
        window.gcStartMpinEntry = function () { reset(); showOnly(mpinEntryCard); };
    })();

    function unlockForm() {
        accountName.innerText = maskedName;
        accountInitial.innerText = maskedName.charAt(0);
        showOnly(formCard);
        confirmBtn.style.display = 'flex';
        confirmBtn.disabled = true;
    }

    // ---- Boot: wait out the splash, then verify the session-based account ----
    function boot() {
        fetch('wallet_topup_verify.php')
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    maskedName = data.masked_name;
                    if (data.needs_mpin_setup) {
                        gcStartMpinSetup();
                    } else {
                        mpinAccountName.innerText = maskedName;
                        mpinAccountInitial.innerText = maskedName.charAt(0);
                        gcStartMpinEntry();
                    }
                } else {
                    errorMessage.innerText = data.error || 'This session is no longer valid.';
                    showOnly(errorCard);
                }
            })
            .catch(function () {
                errorMessage.innerText = 'Could not reach G-Cosh right now. Please check your connection and try again.';
                showOnly(errorCard);
            });
    }

    const elapsed = Date.now() - splashStart;
    const remaining = Math.max(0, SPLASH_MIN_MS - elapsed);
    setTimeout(function () {
        hideSplash();
        boot();
    }, remaining);
})();
</script>
</body>
</html>