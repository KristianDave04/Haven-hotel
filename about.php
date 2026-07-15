<?php
// Initialize session tracking context safely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Track authenticated identity configurations dynamically
$is_logged_in = isset($_SESSION['user_id']);
$user_name    = $is_logged_in ? ($_SESSION['user_name'] ?? 'Guest') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Us | Haven Hotel</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/aos@2.3.4/dist/aos.css"/>

    <link rel="stylesheet" href="ui/about.css">
    
    <style>
        /* =========================================================================
           PROFILE ACTION MATRIX NAVIGATION DROPDOWN CSS (CLICK INITIALIZED)
        ========================================================================= */
        .nav-actions-group {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        .profile-dropdown-wrapper {
            position: relative;
            display: inline-block;
        }

        .profile-avatar-trigger {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.25);
            color: white;
            width: 42px;
            height: 42px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            outline: none;
        }

        /* Active styling applied when menu is open */
        .profile-avatar-trigger:focus,
        .profile-avatar-trigger.active {
            border-color: #d4af37;
            color: #d4af37;
            background: rgba(198, 156, 79, 0.05);
        }

        .profile-dropdown-menu {
            position: absolute;
            top: 130%;
            right: 0;
            width: 200px;
            background: #ffffff;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
            border: 1px solid #f2eade;
            padding: 12px 0;
            
            /* Hidden state defaults */
            display: none; 
            opacity: 0;
            transform: translateY(10px);
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1100;
            text-align: left;
        }

        /* The JavaScript toggles this class to show the menu */
        .profile-dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translateY(0);
        }

        .dropdown-user-meta {
            padding: 8px 20px 12px 20px;
        }

        .dropdown-user-meta .user-greeting {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0;
        }

        .dropdown-user-meta .user-profile-name {
            font-size: 15px;
            font-weight: 600;
            color: #111;
            margin-top: 2px;
            font-family: 'Poppins', sans-serif;
        }

        .dropdown-divider {
            border: 0;
            height: 1px;
            background: #f2eade;
            margin: 4px 0 8px 0;
        }

        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 20px;
            color: #555;
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .dropdown-item i {
            font-size: 14px;
            color: #888;
            width: 16px;
            text-align: center;
        }

        .dropdown-item:hover {
            background: #FAF8F5;
            color: #c69c4f;
        }

        .dropdown-item:hover i {
            color: #c69c4f;
        }

        .dropdown-item.logout-action:hover {
            background: #fff5f5;
            color: #ef4444;
        }

        .dropdown-item.logout-action:hover i {
            color: #ef4444;
        }

        .guest-login-link {
            color: #ffffff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: color 0.3s ease;
        }
        
        .guest-login-link:hover {
            color: #c69c4f;
        }

        /* about.css */

*{
    margin:0;
    padding:0;
    box-sizing:border-box;
    scroll-behavior:smooth;
}

body{
    font-family:'Poppins',sans-serif;
    background:#f8f5ef;
    color:#111;
}

/* NAVBAR */

.navbar{
    position:fixed;
    top:0;
    width:100%;
    padding:20px 8%;
    display:flex;
    justify-content:space-between;
    align-items:center;
    z-index:1000;
    background:rgba(0,0,0,0.3);
    backdrop-filter:blur(12px);
}

.logo{
    color:white;
    font-size:30px;
    font-weight:700;
    font-family:'Playfair Display',serif;
}

.logo span{
    color:#d4af37;
}

.nav-links{
    display:flex;
    gap:35px;
    list-style:none;
}

.nav-links a{
    color:white;
    text-decoration:none;
    transition:.3s;
    position:relative;
}

.nav-links a:hover,
.nav-links .active{
    color:#d4af37;
}

.nav-links .active::after{
    content:'';
    position:absolute;
    bottom:-6px;
    left:0;
    width:100%;
    height:2px;
    background:#d4af37;
}

.nav-btn{
    background:#d4af37;
    color:white;
    padding:12px 24px;
    border-radius:30px;
    text-decoration:none;
    transition:.3s;
}
.nav-btn:hover{
    transform:translateY(-3px);
    box-shadow:0 10px 20px rgba(212,175,55,0.35);
}


/* HERO */

.about-hero{
    position:relative;
    height:78vh;
    background:
    url('https://images.unsplash.com/photo-1566073771259-6a8506099945?q=80&w=1600&auto=format&fit=crop')
    center/cover;
    display:flex;
    justify-content:center;
    align-items:center;
    text-align:center;
}

.overlay{
    position:absolute;
    inset:0;
    background:linear-gradient(180deg, rgba(0,0,0,0.35) 0%, rgba(0,0,0,0.6) 100%);
}

.hero-content{
    position:relative;
    z-index:2;
    color:white;
    max-width:800px;
    padding:0 20px;
}

.hero-eyebrow{
    display:inline-block;
    color:#d4af37;
    letter-spacing:3px;
    font-size:13px;
    font-weight:600;
    margin-bottom:18px;
    padding-bottom:10px;
    border-bottom:1px solid rgba(212,175,55,0.4);
}

.hero-content h1{
    font-size:72px;
    margin:10px 0 22px;
    font-family:'Playfair Display',serif;
    line-height:1.1;
}

.hero-content p{
    line-height:1.8;
    font-size:16.5px;
    color:rgba(255,255,255,0.9);
}

.hero-scroll-cue{
    margin-top:45px;
    color:rgba(255,255,255,0.65);
    font-size:18px;
    animation:heroScrollBounce 2s ease-in-out infinite;
}
@keyframes heroScrollBounce{
    0%,100%{ transform:translateY(0); opacity:0.6; }
    50%{ transform:translateY(8px); opacity:1; }
}

/* STORY */

.story-section{
    padding:110px 8%;
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:70px;
    align-items:center;
}

.story-image{
    position:relative;
}

.story-image img{
    width:100%;
    border-radius:30px;
    box-shadow:0 25px 50px rgba(0,0,0,0.15);
}

.story-image-badge{
    position:absolute;
    bottom:-24px;
    right:-24px;
    background:#111827;
    color:white;
    border-radius:20px;
    padding:20px 26px;
    box-shadow:0 15px 35px rgba(0,0,0,0.25);
    display:flex;
    align-items:center;
    gap:14px;
    border:4px solid #f8f5ef;
}
.story-image-badge strong{
    font-size:32px;
    color:#d4af37;
    font-family:'Playfair Display',serif;
    line-height:1;
}
.story-image-badge span{
    font-size:11.5px;
    line-height:1.4;
    color:#e2e8f0;
    font-weight:500;
}

.story-content span,
.section-title span{
    color:#d4af37;
    letter-spacing:2px;
    font-weight:600;
    font-size:13px;
}

.story-content h2,
.section-title h2{
    font-size:50px;
    margin:20px 0;
    font-family:'Playfair Display',serif;
}

.story-content p{
    color:#666;
    line-height:1.9;
    margin-bottom:20px;
}

/* STATS */

.stats{
    display:flex;
    gap:24px;
    margin-top:40px;
}

.stat-box{
    background:white;
    padding:26px 22px;
    border-radius:20px;
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
    text-align:center;
    flex:1;
    transition:transform .3s ease, box-shadow .3s ease;
}
.stat-box:hover{
    transform:translateY(-6px);
    box-shadow:0 20px 40px rgba(0,0,0,0.1);
}

.stat-box h3{
    color:#d4af37;
    font-size:34px;
    font-family:'Playfair Display',serif;
}
.stat-box p{
    font-size:12.5px;
    color:#777;
    margin-top:6px;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:0.4px;
}

/* MISSION */

.mission-vision{
    padding:110px 8%;
    background:#111827;
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
    gap:30px;
}

.mv-card{
    background:#1f2937;
    padding:44px 36px;
    border-radius:25px;
    color:white;
    text-align:center;
    transition:transform .3s ease, background .3s ease;
    border:1px solid rgba(255,255,255,0.06);
}
.mv-card:hover{
    transform:translateY(-8px);
    background:#232f42;
}

.mv-card i{
    font-size:42px;
    color:#d4af37;
    margin-bottom:22px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:78px;
    height:78px;
    background:rgba(212,175,55,0.1);
    border-radius:50%;
}

.mv-card h3{
    margin-bottom:15px;
    font-family:'Playfair Display',serif;
    font-size:21px;
}
.mv-card p{
    color:#cbd5e1;
    line-height:1.7;
    font-size:14px;
}

/* SERVICES */

.services{
    padding:110px 8%;
}

.section-title{
    text-align:center;
    margin-bottom:60px;
}

.service-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:30px;
}

.service-card{
    background:white;
    padding:44px 34px;
    border-radius:25px;
    text-align:center;
    box-shadow:0 10px 30px rgba(0,0,0,0.08);
    transition:.3s;
}

.service-card:hover{
    transform:translateY(-8px);
    box-shadow:0 20px 40px rgba(0,0,0,0.1);
}

.service-card i{
    font-size:42px;
    color:#d4af37;
    margin-bottom:20px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:74px;
    height:74px;
    background:#faf5e8;
    border-radius:50%;
}
.service-card h3{
    font-family:'Playfair Display',serif;
    font-size:19px;
    margin-bottom:10px;
    color:#111;
}
.service-card p{
    color:#666;
    font-size:13.5px;
    line-height:1.6;
}

/* TEAM */

.team-section{
    padding:110px 8%;
    background:#fff;
}

.team-subtitle{
    margin-top:14px;
    color:#777;
    font-size:14.5px;
    max-width:440px;
    margin-left:auto;
    margin-right:auto;
    line-height:1.6;
}

.team-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:30px;
}

.team-card{
    background:#f8f5ef;
    border-radius:25px;
    overflow:hidden;
    transition:.3s;
    box-shadow:0 4px 20px rgba(0,0,0,0.03);
    position:relative;
    cursor:pointer;
}

.team-card:hover,
.team-card:focus-visible{
    transform:translateY(-8px);
    box-shadow:0 20px 40px rgba(0,0,0,0.08);
    outline:none;
}

.team-card:focus-visible{
    box-shadow:0 20px 40px rgba(0,0,0,0.08), 0 0 0 3px rgba(212,175,55,0.55);
}

.team-card-img-wrap{
    overflow:hidden;
    height:280px;
    position:relative;
}

.team-card img{
    width:100%;
    height:100%;
    object-fit:cover;
    transition:transform .5s ease;
}
.team-card:hover img{
    transform:scale(1.06);
}

.team-card-hint{
    position:absolute;
    top:16px;
    right:16px;
    background:rgba(17,17,17,0.55);
    backdrop-filter:blur(6px);
    color:#fff;
    font-size:11px;
    font-weight:600;
    letter-spacing:0.4px;
    padding:8px 14px;
    border-radius:30px;
    display:flex;
    align-items:center;
    gap:7px;
    opacity:0;
    transform:translateY(-6px);
    transition:opacity .3s ease, transform .3s ease;
    border:1px solid rgba(255,255,255,0.25);
    pointer-events:none;
}
.team-card-hint i{
    color:#d4af37;
    font-size:11px;
}
.team-card:hover .team-card-hint,
.team-card:focus-visible .team-card-hint{
    opacity:1;
    transform:translateY(0);
}

.team-content{
    padding:22px 20px;
    text-align:center;
}
.team-content h3{
    font-size:16px;
    color:#111;
    font-weight:600;
}

.team-content p{
    color:#d4af37;
    margin-top:8px;
    font-size:12.5px;
    line-height:1.5;
}

/* ============================================================
   3D TEAM MODAL
============================================================ */

.model-modal-overlay{
    position:fixed;
    inset:0;
    z-index:3000;
    background:rgba(6,8,14,0.82);
    backdrop-filter:blur(10px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding:30px;
    opacity:0;
    visibility:hidden;
    transition:opacity .35s ease, visibility 0s linear .35s;
}
.model-modal-overlay.is-open{
    opacity:1;
    visibility:visible;
    transition:opacity .35s ease, visibility 0s linear 0s;
}

.model-modal{
    position:relative;
    width:min(1140px, 100%);
    max-height:88vh;
    background:#0b0f1a;
    border-radius:28px;
    overflow:hidden;
    display:grid;
    grid-template-columns:1.15fr 1fr;
    box-shadow:0 40px 90px rgba(0,0,0,0.55);
    border:1px solid rgba(212,175,55,0.18);
    transform:scale(0.94) translateY(16px);
    transition:transform .4s cubic-bezier(.2,.9,.25,1);
}
.model-modal-overlay.is-open .model-modal{
    transform:scale(1) translateY(0);
}

.model-modal-close{
    position:absolute;
    top:18px;
    right:18px;
    z-index:20;
    width:42px;
    height:42px;
    border-radius:50%;
    background:rgba(255,255,255,0.08);
    border:1px solid rgba(255,255,255,0.18);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:16px;
    cursor:pointer;
    transition:.25s;
}
.model-modal-close:hover{
    background:#d4af37;
    border-color:#d4af37;
    color:#111;
    transform:rotate(90deg);
}

/* STAGE (left) */

.model-stage{
    position:relative;
    background:
        radial-gradient(circle at 50% 38%, rgba(212,175,55,0.16) 0%, rgba(212,175,55,0) 60%),
        linear-gradient(180deg, #0b0f1a 0%, #060810 100%);
    min-height:420px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
}

.model-stage canvas{
    display:block;
    touch-action:none;
    cursor:grab;
}
.model-stage canvas:active{
    cursor:grabbing;
}

.model-stage-ring{
    position:absolute;
    bottom:9%;
    left:50%;
    width:56%;
    aspect-ratio:5/1;
    transform:translateX(-50%);
    border:1px solid rgba(212,175,55,0.35);
    border-radius:50%;
    box-shadow:0 0 40px rgba(212,175,55,0.12);
    pointer-events:none;
}

.model-stage-badge{
    position:absolute;
    top:20px;
    left:24px;
    display:flex;
    align-items:center;
    gap:8px;
    color:rgba(255,255,255,0.55);
    font-size:11px;
    letter-spacing:1.5px;
    text-transform:uppercase;
    font-weight:600;
}
.model-stage-badge i{
    color:#d4af37;
}

.model-drag-hint{
    position:absolute;
    bottom:16px;
    left:50%;
    transform:translateX(-50%);
    color:rgba(255,255,255,0.45);
    font-size:11.5px;
    letter-spacing:0.3px;
    display:flex;
    align-items:center;
    gap:8px;
    transition:opacity .4s ease;
}
.model-drag-hint i{
    color:#d4af37;
}

.model-loading{
    position:absolute;
    inset:0;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:16px;
    background:#0b0f1a;
    z-index:10;
    transition:opacity .4s ease, visibility 0s linear 0s;
}
.model-loading.is-hidden{
    opacity:0;
    visibility:hidden;
    transition:opacity .4s ease, visibility 0s linear .4s;
}

.model-loading-ring{
    width:56px;
    height:56px;
    border-radius:50%;
    border:3px solid rgba(212,175,55,0.18);
    border-top-color:#d4af37;
    animation:modelSpin 0.9s linear infinite;
}
@keyframes modelSpin{
    to{ transform:rotate(360deg); }
}

.model-loading-track{
    width:180px;
    height:4px;
    border-radius:4px;
    background:rgba(255,255,255,0.1);
    overflow:hidden;
}
.model-loading-bar{
    height:100%;
    width:0%;
    background:#d4af37;
    border-radius:4px;
    transition:width .2s ease;
}

.model-loading-text{
    color:rgba(255,255,255,0.55);
    font-size:12px;
    letter-spacing:0.4px;
}

.model-error{
    position:absolute;
    inset:0;
    display:none;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:10px;
    text-align:center;
    padding:0 40px;
    background:#0b0f1a;
    z-index:9;
}
.model-error.is-visible{
    display:flex;
}
.model-error i{
    font-size:30px;
    color:#d4af37;
}
.model-error p{
    color:rgba(255,255,255,0.6);
    font-size:13px;
    line-height:1.6;
}

/* INFO PANEL (right) */

.model-info{
    padding:52px 46px;
    color:#fff;
    display:flex;
    flex-direction:column;
    justify-content:center;
    background:#111827;
    position:relative;
}

.model-info-eyebrow{
    color:#d4af37;
    letter-spacing:2.5px;
    font-size:12px;
    font-weight:600;
    text-transform:uppercase;
}

.model-info h2{
    font-family:'Playfair Display',serif;
    font-size:34px;
    margin:14px 0 6px;
    line-height:1.15;
}

.model-info-role{
    color:#9ca8bd;
    font-size:13.5px;
    margin-bottom:26px;
}

.model-info-stats{
    display:flex;
    gap:16px;
    margin-bottom:28px;
}

.model-info-stat{
    background:rgba(255,255,255,0.05);
    border:1px solid rgba(255,255,255,0.08);
    border-radius:16px;
    padding:16px 20px;
    flex:1;
}
.model-info-stat span{
    display:block;
    color:#7b8497;
    font-size:10.5px;
    text-transform:uppercase;
    letter-spacing:1px;
    font-weight:600;
    margin-bottom:6px;
}
.model-info-stat strong{
    font-family:'Playfair Display',serif;
    font-size:20px;
    color:#fff;
    font-weight:600;
}
.model-info-stat.hobby strong{
    font-size:13.5px;
    font-weight:500;
    line-height:1.5;
    font-family:'Poppins',sans-serif;
    color:#e5e9f0;
}

.model-info-quote{
    position:relative;
    padding:26px 8px 10px 34px;
    border-left:2px solid #d4af37;
    margin-top:4px;
}
.model-info-quote i{
    position:absolute;
    top:0;
    left:0;
    font-size:22px;
    color:#d4af37;
    opacity:0.85;
}
.model-info-quote p{
    font-family:'Playfair Display',serif;
    font-style:italic;
    font-size:17px;
    line-height:1.6;
    color:#f1eee6;
}

@media(max-width:860px){
    .model-modal{
        grid-template-columns:1fr;
        max-height:92vh;
        overflow-y:auto;
    }
    .model-stage{
        min-height:320px;
    }
    .model-info{
        padding:36px 30px 44px;
    }
    .model-info h2{
        font-size:28px;
    }
}

/* FOOTER */

.footer{
    background:#0f172a;
    color:white;
    padding:80px 8% 30px;
}

.footer-grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(250px,1fr));
    gap:40px;
}

.footer h3{
    margin-bottom:20px;
}

.footer p,
.footer a{
    color:#ccc;
    text-decoration:none;
    line-height:2;
    transition:color .2s ease;
}
.footer a:hover{
    color:#d4af37;
}

.socials{
    display:flex;
    gap:15px;
    margin-top:20px;
    font-size:20px;
}

.footer-bottom{
    text-align:center;
    margin-top:50px;
    padding-top:20px;
    border-top:1px solid rgba(255,255,255,0.1);
    color:#94a3b8;
    font-size:13px;
}

/* RESPONSIVE */

@media(max-width:1000px){

    .story-section{
        grid-template-columns:1fr;
    }

    .hero-content h1{
        font-size:50px;
    }

    .story-image-badge{
        right:14px;
    }

}

@media(max-width:768px){

    .nav-links{
        display:none;
    }

    .stats{
        flex-direction:column;
    }

    .hero-content h1{
        font-size:40px;
    }

    .story-content h2,
    .section-title h2{
        font-size:38px;
    }

    .story-image-badge{
        position:static;
        margin-top:16px;
        border:none;
        display:inline-flex;
    }

}

@media(max-width:480px){

    .model-info-stats{
        flex-direction:column;
    }

    .model-info h2{
        font-size:24px;
    }

    .model-modal-close{
        top:12px;
        right:12px;
    }

}
    </style>
</head>
<body>

<header class="navbar">

    <div class="logo">
        Haven<span>Hotel</span>
    </div>

    <nav>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php" class="active">About</a></li>
            <li><a href="index.php #rooms">Accommodations</a></li>
            <li><a href="index.php #booking">Booking</a></li>
            <li><a href="index.php #overview">Overview</a></li>
            <li><a href="index.php #contact">Contact</a></li>
        </ul>
    </nav>

    <div class="nav-actions-group">
        <a href="book.php" class="nav-btn">Book Now</a>

        <?php if ($is_logged_in): ?>
            <div class="profile-dropdown-wrapper">
                <button class="profile-avatar-trigger" id="profileDropdownBtn" aria-label="User Account Menu">
                    <i class="fa-regular fa-user"></i>
                </button>
                <div class="profile-dropdown-menu" id="profileDropdownMenu">
                    <div class="dropdown-user-meta">
                        <p class="user-greeting">Welcome,</p>
                        <p class="user-profile-name"><?= htmlspecialchars(explode(' ', $user_name)[0]); ?></p>
                    </div>
                    <hr class="dropdown-divider">
                    <a href="dashboard.php" class="dropdown-item">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                    <a href="login.php" class="dropdown-item logout-action">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>
            </div>
        <?php else: ?>
            <a href="login.php" class="guest-login-link"><i class="fa-solid fa-arrow-right-to-bracket"></i> Login</a>
        <?php endif; ?>
    </div>

</header>

<section class="about-hero">

    <div class="overlay"></div>

    <div class="hero-content" data-aos="fade-up">

        <span class="hero-eyebrow">WELCOME TO HAVEN HOTEL</span>

        <h1>Luxury, Comfort & Hospitality</h1>

        <p>
            Discover elegance, relaxation, and unforgettable experiences
            designed to make every guest feel at home.
        </p>

        <div class="hero-scroll-cue">
            <i class="fa-solid fa-chevron-down"></i>
        </div>

    </div>

</section>

<section class="story-section">

    <div class="story-image" data-aos="fade-right">

        <img src="https://images.unsplash.com/photo-1566073771259-6a8506099945?q=80&w=1600&auto=format&fit=crop" alt="Haven Hotel Interior">
        <div class="story-image-badge">
            <strong>5+</strong>
            <span>Years of<br>Excellence</span>
        </div>

    </div>

    <div class="story-content" data-aos="fade-left">

        <span>OUR STORY</span>

        <h2>Where Luxury Meets Serenity</h2>

        <p>
            Haven Hotel was established with one vision:
            to create a world-class luxury destination where guests can relax,
            recharge, and create unforgettable memories.
        </p>

        <p>
            Inspired by modern elegance and timeless hospitality,
            Haven Hotel combines premium accommodations,
            exceptional service, and breathtaking spaces
            to provide a truly luxurious experience.
        </p>

        <div class="stats">
            <div class="stat-box" data-aos="zoom-in" data-aos-delay="0">
                <h3>150+</h3>
                <p>Luxury Rooms</p>
            </div>
            <div class="stat-box" data-aos="zoom-in" data-aos-delay="100">
                <h3>20k+</h3>
                <p>Happy Guests</p>
            </div>
            <div class="stat-box" data-aos="zoom-in" data-aos-delay="200">
                <h3>4.9</h3>
                <p>Guest Rating</p>
            </div>
        </div>

    </div>

</section>

<section class="mission-vision">

    <div class="mv-card" data-aos="fade-up">

        <i class="fa-solid fa-bullseye"></i>

        <h3>Our Mission</h3>

        <p>
            To deliver exceptional hospitality experiences
            through luxurious accommodations, premium services,
            and heartfelt customer care.
        </p>

    </div>

    <div class="mv-card" data-aos="fade-up" data-aos-delay="100">

        <i class="fa-solid fa-eye"></i>

        <h3>Our Vision</h3>

        <p>
            To become the leading luxury hotel destination
            known for elegance, comfort, and world-class hospitality.
        </p>

    </div>

    <div class="mv-card" data-aos="fade-up" data-aos-delay="200">

        <i class="fa-solid fa-heart"></i>

        <h3>Our Values</h3>

        <p>
            Excellent, Integrity, Comfort, Innovation,
            and Genuine Hospitality are at the heart of everything we do.
        </p>

    </div>

</section>

<section class="services">

    <div class="section-title" data-aos="fade-up">

        <span>WHAT WE OFFER</span>

        <h2>Luxury Services & Amenities</h2>

    </div>

    <div class="service-grid">

        <div class="service-card" data-aos="fade-up" data-aos-delay="0">
            <i class="fa-solid fa-bed"></i>
            <h3>Luxury Suites</h3>
            <p>Elegant rooms designed for comfort and relaxation.</p>
        </div>

        <div class="service-card" data-aos="fade-up" data-aos-delay="50">
            <i class="fa-solid fa-spa"></i>
            <h3>Spa & Wellness</h3>
            <p>Premium spa experiences for complete rejuvenation.</p>
        </div>

        <div class="service-card" data-aos="fade-up" data-aos-delay="100">
            <i class="fa-solid fa-utensils"></i>
            <h3>Fine Dining</h3>
            <p>World-class culinary experiences from expert chefs.</p>
        </div>

        <div class="service-card" data-aos="fade-up" data-aos-delay="150">
            <i class="fa-solid fa-water-ladder"></i>
            <h3>Infinity Pool</h3>
            <p>Relax beside our luxurious infinity pool area.</p>
        </div>

        <div class="service-card" data-aos="fade-up" data-aos-delay="200">
            <i class="fa-solid fa-dumbbell"></i>
            <h3>Fitness Center</h3>
            <p>Modern gym facilities for health and wellness.</p>
        </div>

        <div class="service-card" data-aos="fade-up" data-aos-delay="250">
            <i class="fa-solid fa-wifi"></i>
            <h3>High-Speed WiFi</h3>
            <p>Fast and reliable internet throughout the hotel.</p>
        </div>

    </div>

</section>

<section class="team-section">

    <div class="section-title" data-aos="fade-up">

        <span>OUR TEAM</span>

        <h2>Meet Our Programming Team</h2>

        <p class="team-subtitle">Tap a card to spin up their model in 3D and see their full profile.</p>

    </div>

    <div class="team-grid">

        <div class="team-card" data-aos="fade-up" data-aos-delay="0" data-member="kd" tabindex="0" role="button" aria-label="View Kristian Dave B. Argate in 3D">
            <div class="team-card-img-wrap">
                <img src="./assets/argate.JPG" alt="Kristian Dave B. Argate">
            </div>
            <div class="team-card-hint"><i class="fa-solid fa-cube"></i> View in 3D</div>
            <div class="team-content">
                <h3>Kristian Dave B. Argate</h3>
                <p>Lead Programmer/Developer<br>/Designer</p>
            </div>
        </div>

        <div class="team-card" data-aos="fade-up" data-aos-delay="50" data-member="paul" tabindex="0" role="button" aria-label="View Paul Edward T. Lintad in 3D">
            <div class="team-card-img-wrap">
                <img src="./assets/lintad.JPG" alt="Paul Edward Lintad">
            </div>
            <div class="team-card-hint"><i class="fa-solid fa-cube"></i> View in 3D</div>
            <div class="team-content">
                <h3>Paul Edward Lintad</h3>
                <p>Programmer/Developer<br>/Designer</p>
            </div>
        </div>

        <div class="team-card" data-aos="fade-up" data-aos-delay="100" data-member="jane" tabindex="0" role="button" aria-label="View Maryjane M. Encaja in 3D">
            <div class="team-card-img-wrap">
                <img src="./assets/encaja.JPG" alt="Maryjane Encaja">
            </div>
            <div class="team-card-hint"><i class="fa-solid fa-cube"></i> View in 3D</div>
            <div class="team-content">
                <h3>Maryjane Encaja</h3>
                <p>Lead Designer/<br>/Programmer/Developer</p>
            </div>
        </div>

        <div class="team-card" data-aos="fade-up" data-aos-delay="150" data-member="sapin" tabindex="0" role="button" aria-label="View Sophia Angela D. Sapin in 3D">
            <div class="team-card-img-wrap">
                <img src="./assets/sapin.JPG" alt="Angela Sapin">
            </div>
            <div class="team-card-hint"><i class="fa-solid fa-cube"></i> View in 3D</div>
            <div class="team-content">
                <h3>Angela Sapin</h3>
                <p>ERD & Database Designer</p>
            </div>
        </div>

        <div class="team-card" data-aos="fade-up" data-aos-delay="200" data-member="mark" tabindex="0" role="button" aria-label="View Mark John G. Fernandez in 3D">
            <div class="team-card-img-wrap">
                <img src="./assets/mark.jpg" alt="Mark John G. Fernandez">
            </div>
            <div class="team-card-hint"><i class="fa-solid fa-cube"></i> View in 3D</div>
            <div class="team-content">
                <h3>Mark John G. Fernandez</h3>
                <p>Planner & Database Designer</p>
            </div>
        </div>

    </div>

</section>

<!-- ================= 3D TEAM MODAL ================= -->
<div class="model-modal-overlay" id="modelModalOverlay" aria-hidden="true">
    <div class="model-modal" role="dialog" aria-modal="true" aria-labelledby="modelInfoName">

        <button class="model-modal-close" id="modelModalClose" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>

        <div class="model-stage" id="modelStage">

            <div class="model-stage-badge"><i class="fa-solid fa-cube"></i> 3D Preview</div>

            <div class="model-stage-ring"></div>

            <div class="model-drag-hint" id="modelDragHint">
                <i class="fa-solid fa-arrows-rotate"></i> Drag to rotate
            </div>

            <div class="model-loading" id="modelLoading">
                <div class="model-loading-ring"></div>
                <div class="model-loading-track">
                    <div class="model-loading-bar" id="modelLoadingBar"></div>
                </div>
                <div class="model-loading-text" id="modelLoadingText">Loading model&hellip; 0%</div>
            </div>

            <div class="model-error" id="modelError">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <p>This 3D model couldn&rsquo;t be loaded.<br>Please check your connection and try again.</p>
            </div>

        </div>

        <div class="model-info">
            <span class="model-info-eyebrow">Team Member</span>
            <h2 id="modelInfoName">&nbsp;</h2>
            <p class="model-info-role" id="modelInfoRole">&nbsp;</p>

            <div class="model-info-stats">
                <div class="model-info-stat">
                    <span>Age</span>
                    <strong id="modelInfoAge">&mdash;</strong>
                </div>
                <div class="model-info-stat hobby">
                    <span>Hobbies</span>
                    <strong id="modelInfoHobby">&mdash;</strong>
                </div>
            </div>

            <div class="model-info-quote">
                <i class="fa-solid fa-quote-left"></i>
                <p id="modelInfoQuote">&nbsp;</p>
            </div>
        </div>

    </div>
</div>

<footer class="footer">
    <div class="footer-bottom"> 
        &copy; 2026 Haven Hotel. All Rights Reserved.
    </div>
</footer>

<script src="https://unpkg.com/aos@2.3.4/dist/aos.js"></script>
<script>
    AOS.init({
        duration: 900,
        once: true
    });

    document.addEventListener("DOMContentLoaded", function() {
        const dropdownBtn = document.getElementById("profileDropdownBtn");
        const dropdownMenu = document.getElementById("profileDropdownMenu");

        // Only handle clicks if user identity metrics are verified and elements exist
        if (dropdownBtn && dropdownMenu) {
            dropdownBtn.addEventListener("click", function(event) {
                // Prevent event bubbling up to the window bounds
                event.stopPropagation(); 
                
                dropdownMenu.classList.toggle("show");
                dropdownBtn.classList.toggle("active");
            });

            // Dismiss menu instantly if click actions fall anywhere outside the boundary box
            window.addEventListener("click", function(event) {
                if (!dropdownBtn.contains(event.target) && !dropdownMenu.contains(event.target)) {
                    dropdownMenu.classList.remove("show");
                    dropdownBtn.classList.remove("active");
                }
            });
        }
    });
</script>

<!-- ================= 3D TEAM VIEWER (Three.js) ================= -->
<script type="importmap">
{
    "imports": {
        "three": "https://unpkg.com/three@0.160.0/build/three.module.js",
        "three/addons/": "https://unpkg.com/three@0.160.0/examples/jsm/"
    }
}
</script>

<script type="module">
import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";

/* ---------------------------------------------------------------
   Where the .glb files live relative to this page. They're
   expected in the SAME FOLDER as about.php. If you move them
   into a subfolder (e.g. "models/"), change this one line —
   everything below reads from it.
----------------------------------------------------------------*/
const MODELS_BASE_PATH = "./";

/* ---------------------------------------------------------------
   Team data — each key matches a team-card's data-member attribute.
----------------------------------------------------------------*/
const TEAM_DATA = {
    kd: {
        name: "Kristian Dave B. Argate",
        role: "Lead Programmer / Developer / Designer",
        age: "21",
        hobby: "Reading, Coding, Watching movies",
        quote: "Life is a fork, and I'm a spoon.",
        file: "assets/model/KD.glb"
    },
    paul: {
        name: "Paul Edward T. Lintad",
        role: "Programmer / Developer / Designer",
        age: "19",
        hobby: "Playing online games, singing, watching horror movies",
        quote: "Code. Run. Error. Cry. Repeat.",
        file: "assets/model/PAUL.glb"
    },
    jane: {
        name: "Maryjane M. Encaja",
        role: "Lead Designer / Programmer / Developer",
        age: "20",
        hobby: "Playing online games, reading, watching anime",
        quote: "Spaghetti is straight until it gets wet \u2014 Money is Everything. Money = Happy Life.",
        file: "assets/model/JANE.glb"
    },
    mark: {
        name: "Mark John G. Fernandez",
        role: "Planner & Database Designer",
        age: "20",
        hobby: "Basketball",
        quote: "Pag nakita mo silang nahihirapan, wag mong tulungan.",
        file: "assets/model/MARK.glb"
    },
    sapin: {
        name: "Sophia Angela D. Sapin",
        role: "ERD & Database Designer",
        age: "20",
        hobby: "Reading, Drawing, Playing Badminton",
        quote: "Habang may baon, babangon.",
        file: "assets/model/SAPIN.glb"
    }
};

/* ---------------------------------------------------------------
   DOM references
----------------------------------------------------------------*/
const overlay      = document.getElementById("modelModalOverlay");
const stageEl       = document.getElementById("modelStage");
const closeBtn      = document.getElementById("modelModalClose");
const loadingEl     = document.getElementById("modelLoading");
const loadingBar    = document.getElementById("modelLoadingBar");
const loadingText   = document.getElementById("modelLoadingText");
const errorEl       = document.getElementById("modelError");
const dragHintEl    = document.getElementById("modelDragHint");

const infoName  = document.getElementById("modelInfoName");
const infoRole  = document.getElementById("modelInfoRole");
const infoAge   = document.getElementById("modelInfoAge");
const infoHobby = document.getElementById("modelInfoHobby");
const infoQuote = document.getElementById("modelInfoQuote");

const teamCards = document.querySelectorAll(".team-card[data-member]");

/* ---------------------------------------------------------------
   Three.js singleton scene — one renderer/scene reused for every
   member so we're not spinning up a new WebGL context per click.
----------------------------------------------------------------*/
let renderer, scene, camera, controls, currentModel;
let resumeTimer = null;
let rafId = null; // not cancelled anywhere on purpose — the render loop runs for the page's lifetime
let sceneReady = false;
const AUTO_ROTATE_SPEED = 0.45;   // radians/sec-ish, applied via controls.autoRotateSpeed units
const RESUME_DELAY_MS   = 1800;   // how long to wait after user lets go before auto-rotate resumes

function initScene() {
    if (sceneReady) return;

    scene = new THREE.Scene();

    camera = new THREE.PerspectiveCamera(35, 1, 0.1, 2000);
    camera.position.set(0, 1.4, 4.2);

    renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.outputColorSpace = THREE.SRGBColorSpace;
    renderer.toneMapping = THREE.ACESFilmicToneMapping;
    renderer.toneMappingExposure = 1.05;
    stageEl.appendChild(renderer.domElement);

    // Lighting: soft key + fill + rim, tuned for a warm gold "showcase" feel
    const keyLight = new THREE.DirectionalLight(0xfff2d9, 2.4);
    keyLight.position.set(3, 5, 4);
    scene.add(keyLight);

    const fillLight = new THREE.DirectionalLight(0xbcd2ff, 0.7);
    fillLight.position.set(-4, 2, -2);
    scene.add(fillLight);

    const rimLight = new THREE.PointLight(0xd4af37, 1.1, 12);
    rimLight.position.set(0, 3, -3);
    scene.add(rimLight);

    scene.add(new THREE.AmbientLight(0xffffff, 0.55));

    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.dampingFactor = 0.08;
    controls.enablePan = false;
    controls.minDistance = 1.5;
    controls.maxDistance = 9;
    controls.autoRotate = true;
    controls.autoRotateSpeed = AUTO_ROTATE_SPEED;
    controls.target.set(0, 1, 0);

    // Any user-driven interaction pauses auto-rotate, then resumes it
    // a couple seconds after they let go.
    controls.addEventListener("start", () => {
        controls.autoRotate = false;
        dragHintEl.style.opacity = "0";
        if (resumeTimer) clearTimeout(resumeTimer);
    });
    controls.addEventListener("end", () => {
        if (resumeTimer) clearTimeout(resumeTimer);
        resumeTimer = setTimeout(() => {
            controls.autoRotate = true;
        }, RESUME_DELAY_MS);
    });

    window.addEventListener("resize", resizeRenderer);

    sceneReady = true;
    animate();
}

function resizeRenderer() {
    if (!renderer || !stageEl) return;
    const w = stageEl.clientWidth;
    const h = stageEl.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function animate() {
    rafId = requestAnimationFrame(animate);
    if (controls) controls.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

/* ---------------------------------------------------------------
   Clears the previously loaded model and frees its GPU memory.
   Important here since some of these .glb files are quite large.
----------------------------------------------------------------*/
function disposeCurrentModel() {
    if (!currentModel) return;
    currentModel.traverse((child) => {
        if (child.isMesh) {
            child.geometry?.dispose();
            const materials = Array.isArray(child.material) ? child.material : [child.material];
            materials.forEach((mat) => {
                if (!mat) return;
                Object.keys(mat).forEach((key) => {
                    const val = mat[key];
                    if (val && val.isTexture) val.dispose();
                });
                mat.dispose();
            });
        }
    });
    scene.remove(currentModel);
    currentModel = null;
}

/* ---------------------------------------------------------------
   Frames the camera/controls target based on the model's actual
   bounding box, so every .glb (different scales/proportions) sits
   centered and fully in view automatically.
----------------------------------------------------------------*/
function frameModel(model) {
    const box = new THREE.Box3().setFromObject(model);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());

    // Re-center the model at the origin, regardless of whatever
    // transform was baked into the .glb's root node.
    model.position.x -= center.x;
    model.position.y -= center.y;
    model.position.z -= center.z;

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    const fitDist = (maxDim / 2) / Math.tan((camera.fov * Math.PI / 180) / 2) * 1.55;

    camera.position.set(0, size.y * 0.15, fitDist);
    controls.target.set(0, size.y * 0.15, 0);
    controls.minDistance = fitDist * 0.35;
    controls.maxDistance = fitDist * 2.4;
    controls.update();
}

const gltfLoader = new GLTFLoader();
let activeLoadToken = 0;

function loadMember(key) {
    const data = TEAM_DATA[key];
    if (!data) return;

    // Populate info panel immediately — no reason to wait on the model
    infoName.textContent  = data.name;
    infoRole.textContent  = data.role;
    infoAge.textContent   = data.age;
    infoHobby.textContent = data.hobby;
    infoQuote.textContent = data.quote;

    initScene();
    disposeCurrentModel();

    errorEl.classList.remove("is-visible");
    loadingEl.classList.remove("is-hidden");
    loadingBar.style.width = "0%";
    loadingText.textContent = "Loading model\u2026 0%";
    dragHintEl.style.opacity = "0";

    // Tag this load so that if the user switches members (or closes and
    // reopens) before it finishes, the stale response is dropped instead
    // of being added to the scene on top of / instead of the new pick.
    const thisLoadToken = ++activeLoadToken;

    gltfLoader.load(
        MODELS_BASE_PATH + data.file,
        (gltf) => {
            if (thisLoadToken !== activeLoadToken) return; // superseded — ignore

            currentModel = gltf.scene;
            currentModel.traverse((child) => {
                if (child.isMesh) {
                    child.castShadow = false;
                    child.receiveShadow = false;
                }
            });
            scene.add(currentModel);
            frameModel(currentModel);

            controls.autoRotate = true;

            loadingEl.classList.add("is-hidden");
            dragHintEl.style.opacity = "1";
            setTimeout(() => { dragHintEl.style.opacity = "0"; }, 3200);

            resizeRenderer();
        },
        (progressEvent) => {
            if (thisLoadToken !== activeLoadToken) return; // superseded — ignore

            if (progressEvent.lengthComputable) {
                const pct = Math.round((progressEvent.loaded / progressEvent.total) * 100);
                loadingBar.style.width = pct + "%";
                loadingText.textContent = "Loading model\u2026 " + pct + "%";
            } else {
                const mb = (progressEvent.loaded / (1024 * 1024)).toFixed(1);
                loadingText.textContent = "Loading model\u2026 " + mb + " MB";
            }
        },
        (err) => {
            if (thisLoadToken !== activeLoadToken) return; // superseded — ignore

            console.error("Failed to load", MODELS_BASE_PATH + data.file, err);
            loadingEl.classList.add("is-hidden");
            errorEl.classList.add("is-visible");
        }
    );
}

/* ---------------------------------------------------------------
   Modal open / close
----------------------------------------------------------------*/
let lastFocusedEl = null;

function openModal(key) {
    lastFocusedEl = document.activeElement;
    overlay.classList.add("is-open");
    overlay.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";
    loadMember(key);
    requestAnimationFrame(resizeRenderer);
    closeBtn.focus();
}

function closeModal() {
    overlay.classList.remove("is-open");
    overlay.setAttribute("aria-hidden", "true");
    document.body.style.overflow = "";
    controls && (controls.autoRotate = true);
    if (resumeTimer) clearTimeout(resumeTimer);
    if (lastFocusedEl && typeof lastFocusedEl.focus === "function") {
        lastFocusedEl.focus();
    }
}

teamCards.forEach((card) => {
    const key = card.getAttribute("data-member");
    card.addEventListener("click", () => openModal(key));
    card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            openModal(key);
        }
    });
});

closeBtn.addEventListener("click", closeModal);

overlay.addEventListener("click", (e) => {
    if (e.target === overlay) closeModal();
});

document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && overlay.classList.contains("is-open")) {
        closeModal();
    }
});
</script>

</body>
</html>