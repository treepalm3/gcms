<?php
// structure.php — โครงสร้างสหกรณ์ (Formal + Mobile-first)
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>โครงสร้างสหกรณ์ — สหกรณ์ชุมชนบ้านภูเขาทอง</title>
  <meta name="description" content="ผังโครงสร้างการบริหาร คณะกรรมการ หน่วยงาน และสายงานหลักของสหกรณ์ชุมชนบ้านภูเขาทอง">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <!-- Global theme -->
  <link rel="stylesheet" href="../assets/css/coop-landing.css" />
  <!-- Page styles -->
  <link rel="stylesheet" href="../assets/css/structure.css" />
</head>
<body>

<!-- Top contact bar -->
<div class="topbar" role="contentinfo">
  <div class="container topbar-inner">
    <div class="tb-left">
      <i class="fa-solid fa-phone" aria-hidden="true"></i>
      <a class="link" href="tel:043123456" aria-label="โทร 043 123 456">043-123-456</a>
      <span class="sep">|</span>
      <i class="fa-regular fa-envelope" aria-hidden="true"></i>
      <a class="link" href="mailto:info@phukhaothong-coop.th">info@phukhaothong-coop.th</a>
    </div>
    <div class="tb-right">
      <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
      <a href="#" aria-label="LINE"><i class="fa-brands fa-line"></i></a>
      <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
    </div>
  </div>
</div>

<!-- Navbar -->
<header class="navbar" role="banner">
  <div class="container nav-inner">
    <a class="brand" href="../index.php#home" aria-label="หน้าแรก">
      <img src="../assets/images/ต.jpg" alt="ตราสหกรณ์" class="brand-mark" />
      <div class="brand-text">
        <div class="brand-name">สหกรณ์ชุมชนบ้านภูเขาทอง</div>
        <div class="brand-sub">ระบบบริหารจัดการปั๊มน้ำมัน</div>
      </div>
    </a>
    <nav class="menu" id="menu" aria-label="เมนูหลัก">
      <a href="../index.php#home">หน้าแรก</a>
      <a href="services.php" >บริการ</a>
      <a href="about.php" >ประวัติสหกรณ์</a>
      <a href="structure.php" class="active">โครงสร้างสหกรณ์</a>
      <a href="login.php" class="btn btn-login"><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ</a>
    </nav>
    <button class="menu-toggle" id="menuToggle" aria-label="เปิด/ปิดเมนู" aria-controls="menu" aria-expanded="false">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</header>

<!-- Hero (sub) -->
<section class="hero has-bg hero--sub" style="background-image:url('../assets/images/23.webp')">
  <div class="hero-overlay"></div>
  <div class="container hero-inner">
    <div class="hero-copy">
      <h1 class="hero-title">โครงสร้างสหกรณ์</h1>
      <p class="hero-subtitle">ผังการบริหารที่กระชับ โปร่งใส รับผิดชอบชัดเจน เพื่อประสิทธิภาพและความไว้วางใจของสมาชิก</p>
    </div>
  </div>
</section>

<!-- Org Chart -->
<section class="section" id="org">
  <div class="container">
    <h2 class="section-title">ผังโครงสร้างการบริหาร</h2>
    <p class="section-lead">
      โครงสร้างแบ่งเป็น 4 สายงานหลัก ได้แก่ ปฏิบัติการ (สถานีบริการ), คลัง/ความปลอดภัย, การเงิน/บัญชี และระบบดิจิทัล/รายงาน
      โดยมีคณะกรรมการกำกับดูแลภาพรวมและธรรมาภิบาล
    </p>

    <div class="org-chart">
      <!-- Top: Board -->
      <div class="org-node org-top">
        <div class="badge">คณะกรรมการ</div>
        <div class="node-title">กำกับดูแล & ธรรมาภิบาล</div>
        <div class="node-sub">ประธาน, รองประธาน, เหรัญญิก, กรรมการ</div>
      </div>

      <!-- Middle: Manager -->
      <div class="org-node org-mid">
        <div class="badge badge-teal">ผู้จัดการสหกรณ์</div>
        <div class="node-title">บูรณาการงานทุกสาย</div>
        <div class="node-sub">แผนงาน | เป้าหมาย | ประสานงาน</div>
      </div>

      <!-- Bottom: 4 pillars -->
      <div class="org-grid">
        <div class="org-node">
          <div class="icon"><i class="fa-solid fa-gas-pump"></i></div>
          <div class="node-title">ปฏิบัติการสถานีบริการ</div>
          <ul class="node-list">
            <li>เคาน์เตอร์ขาย/แคชเชียร์</li>
            <li>ดูแลหัวจ่าย & คุณภาพบริการ</li>
            <li>บันทึกยอดขายประจำวัน</li>
          </ul>
        </div>
        <div class="org-node">
          <div class="icon"><i class="fa-solid fa-warehouse"></i></div>
          <div class="node-title">คลัง & ความปลอดภัย</div>
          <ul class="node-list">
            <li>รับ-จ่ายน้ำมัน/สต๊อก</li>
            <li>ตรวจรั่วไหล & มาตรการความปลอดภัย</li>
            <li>บำรุงรักษาถัง/ท่อ & อุปกรณ์</li>
          </ul>
        </div>
        <div class="org-node">
          <div class="icon"><i class="fa-solid fa-calculator"></i></div>
          <div class="node-title">การเงิน & บัญชี</div>
          <ul class="node-list">
            <li>กระทบยอดรายรับ-รายจ่าย</li>
            <li>งบการเงิน & รายงานสมาชิก</li>
            <li>ควบคุมภายใน</li>
          </ul>
        </div>
        <div class="org-node">
          <div class="icon"><i class="fa-solid fa-laptop-code"></i></div>
          <div class="node-title">ดิจิทัล & รายงาน</div>
          <ul class="node-list">
            <li>ระบบขาย/สต๊อก/บัญชี</li>
            <li>แดชบอร์ด/สถิติ</li>
            <li>สำรองข้อมูล & ความปลอดภัยไซเบอร์</li>
          </ul>
        </div>
      </div>
    </div>

    <div class="org-legend">
      <span class="pill"><i class="fa-solid fa-circle"></i> ควบคุมกำกับ</span>
      <span class="pill pill-teal"><i class="fa-solid fa-circle"></i> บริหารจัดการ</span>
      <span class="pill pill-amber"><i class="fa-solid fa-circle"></i> ปฏิบัติการ</span>
    </div>
  </div>
</section>

<!-- Committee / People -->
<section class="section" id="people">
  <div class="container">
    <h2 class="section-title">คณะกรรมการ/ผู้รับผิดชอบสายงาน</h2>
    <div class="people-grid">
      <div class="person">
        <div class="avatar">ปธ</div>
        <div>
          <div class="name">นางสาวกมลชนก ใจดี</div>
          <div class="role">ประธานกรรมการ</div>
          <div class="muted small">กำกับดูแลธรรมาภิบาล</div>
        </div>
      </div>
      <div class="person">
        <div class="avatar">นย</div>
        <div>
          <div class="name">นายสมชาย มั่นคง</div>
          <div class="role">รองประธาน / ปฏิบัติการ</div>
          <div class="muted small">คุณภาพบริการ & หัวจ่าย</div>
        </div>
      </div>
      <div class="person">
        <div class="avatar">กท</div>
        <div>
          <div class="name">น.ส.ศิริพร ถนอมศักดิ์</div>
          <div class="role">เหรัญญิก / การเงิน</div>
          <div class="muted small">ควบคุมบัญชี & งบการเงิน</div>
        </div>
      </div>
      <div class="person">
        <div class="avatar">สต</div>
        <div>
          <div class="name">นายปรีชา อารี</div>
          <div class="role">คลัง/ความปลอดภัย</div>
          <div class="muted small">รับ-จ่าย & มาตรการความปลอดภัย</div>
        </div>
      </div>
      <div class="person">
        <div class="avatar">ดจ</div>
        <div>
          <div class="name">ทีมดิจิทัล</div>
          <div class="role">ระบบ & รายงาน</div>
          <div class="muted small">แดชบอร์ด/สำรองข้อมูล</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- RACI Mini (ตัวอย่างความรับผิดชอบ) -->
<section class="section" id="raci">
  <div class="container">
    <h2 class="section-title">ตัวอย่างหน้าที่รับผิดชอบ (RACI)</h2>
    <div class="raci">
      <div class="raci-row raci-head">
        <div>กิจกรรม</div><div>ผู้ปฏิบัติ (R)</div><div>ผู้รับผิดชอบ (A)</div><div>ผู้ปรึกษา (C)</div><div>ผู้รับทราบ (I)</div>
      </div>
      <div class="raci-row">
        <div>รับน้ำมันเข้าถัง</div><div>คลัง</div><div>ผู้จัดการ</div><div>ปฏิบัติการ</div><div>การเงิน</div>
      </div>
      <div class="raci-row">
        <div>กระทบยอดรายได้รายวัน</div><div>การเงิน</div><div>เหรัญญิก</div><div>ผู้จัดการ</div><div>คณะกรรมการ</div>
      </div>
      <div class="raci-row">
        <div>ปรับราคาหน้าสถานี</div><div>ปฏิบัติการ</div><div>ผู้จัดการ</div><div>คณะกรรมการ</div><div>สมาชิก</div>
      </div>
    </div>
    <div class="text-center mt-16">
      <a class="btn btn-primary" href="#" aria-label="ดาวน์โหลดผังโครงสร้าง (PDF)"><i class="fa-regular fa-file-pdf"></i> ดาวน์โหลดผังโครงสร้าง (PDF)</a>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="footer">
  <div class="container footer-inner">
    <div class="footer-col">
      <div class="footer-title">เมนู</div>
      <ul class="footer-links">
        <li><a href="../index.php#home">หน้าแรก</a></li>
        <li><a href="services.php">บริการ</a></li>
        <li><a href="about.php">ประวัติสหกรณ์</a></li>
        <li><a href="structure.php">โครงสร้างสหกรณ์</a></li>
      </ul>
    </div>
    <div class="footer-col">
      <div class="footer-title">ติดต่อเรา</div>
      <div class="footer-contact">
        <div><i class="fa-solid fa-location-dot"></i> ต.คำพอุง อ.โพธิ์ชัย จ.ร้อยเอ็ด 46000</div>
        <div><i class="fa-solid fa-phone"></i> 043-123-456</div>
        <div><i class="fa-regular fa-envelope"></i> info@phukhaothong-coop.th</div>
      </div>
    </div>
  </div>
  <div class="copyright">© <?php echo date('Y'); ?> Phukhaothong Community Cooperative</div>
</footer>

<!-- Scroll to top -->
<button class="scroll-top" id="scrollTop" aria-label="เลื่อนไปบนสุด"><i class="fa-solid fa-chevron-up"></i></button>

<script>
  // menu toggle
  const menuToggle = document.getElementById('menuToggle');
  const menu = document.getElementById('menu');
  menuToggle.addEventListener('click',()=>{
    const opened = menu.classList.toggle('active');
    menuToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
  });
  // close menu on item click (mobile)
  menu.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', () => { menu.classList.remove('active'); menuToggle.setAttribute('aria-expanded', 'false'); });
  });
  // smooth scroll (ภายในหน้านี้)
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const id = a.getAttribute('href');
      if (id.length > 1) { e.preventDefault(); document.querySelector(id)?.scrollIntoView({ behavior: 'smooth' }); }
    });
  });
  // scroll top
  const btnTop = document.getElementById('scrollTop');
  window.addEventListener('scroll',()=>{
    if (window.scrollY > 300) btnTop.classList.add('visible'); else btnTop.classList.remove('visible');
  });
  btnTop.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
</script>
</body>
</html>
