<?php
// about.php — หน้าประวัติสหกรณ์ (Formal + Mobile-first)
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>ประวัติสหกรณ์ — สหกรณ์ชุมชนบ้านภูเขาทอง</title>
  <meta name="description" content="ประวัติสหกรณ์ วิสัยทัศน์ พันธกิจ คณะกรรมการ ไทม์ไลน์ และเอกสารสำคัญของสหกรณ์ชุมชนบ้านภูเขาทอง">
  <!-- ฟอนต์เหมือน structure.php -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <!-- ✅ Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <link rel="stylesheet" href="../assets/css/coop-landing.css" />
  <link rel="stylesheet" href="../assets/css/about.css" />
  <style>
    html, body {
      font-family: 'Prompt', system-ui, -apple-system, 'Segoe UI', Roboto, 'Noto Sans Thai', sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
  </style>
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
    <a class="brand" href="index.php#home" aria-label="หน้าแรก">
      <img src="../assets/images/ต.jpg" alt="ตราสหกรณ์" class="brand-mark" />
      <div class="brand-text">
        <div class="brand-name">สหกรณ์ชุมชนบ้านภูเขาทอง</div>
        <div class="brand-sub">ระบบบริหารจัดการปั๊มน้ำมัน</div>
      </div>
    </a>
    <nav class="menu" id="menu" aria-label="เมนูหลัก">
      <a href="../index.php#home">หน้าแรก</a>
      <a href="services.php" >บริการ</a>
      <a href="about.php" class="active">ประวัติสหกรณ์</a>
      <a href="structure.php">โครงสร้างสหกรณ์</a>
      <a href="login.php" class="btn btn-login"><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ</a>
    </nav>
    <button class="menu-toggle" id="menuToggle" aria-label="เปิด/ปิดเมนู" aria-controls="menu" aria-expanded="false">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</header>

<!-- Hero (sub) -->
<section class="hero has-bg" style="background-image:url('../assets/images/23.webp')">
  <div class="hero-overlay"></div>
  <div class="container hero-inner">
    <div class="hero-copy">
      <h1 class="hero-title">ประวัติสหกรณ์</h1>
      <p class="hero-subtitle">จากชุมชนเล็ก ๆ สู่สหกรณ์บริการปั๊มน้ำมันของคนในพื้นที่ เพื่อความโปร่งใส พึ่งพาตนเอง และยั่งยืน</p>
    </div>
  </div>
</section>

<!-- Story -->
<section class="section" id="about">
  <div class="container">
    <h2 class="section-title">เรื่องราวของเรา</h2>
    <p class="section-lead">
      สหกรณ์ชุมชนบ้านภูเขาทองก่อตั้งขึ้นจากความร่วมมือของชาวบ้านในพื้นที่
      เพื่อให้เกิด “แหล่งพลังงานของชุมชนโดยชุมชน” รายได้หมุนเวียนกลับสู่ท้องถิ่น
      ทั้งในรูปแบบกองทุนพัฒนา กิจกรรมสาธารณประโยชน์ และส่วนลดสำหรับสมาชิก
      เราบริหารงานด้วยหลักธรรมาภิบาล เน้นความโปร่งใส ตรวจสอบได้ และใช้เทคโนโลยีช่วยเพิ่มประสิทธิภาพ
    </p>

    <div class="grid-3" style="margin-top:1.2rem">
      <div class="card-soft">
        <h3><i class="fa-solid fa-eye"></i> วิสัยทัศน์</h3>
        <p>เป็นสหกรณ์บริการพลังงานต้นแบบของอีสานที่โปร่งใส ทันสมัย และยั่งยืน</p>
      </div>
      <div class="card-soft">
        <h3><i class="fa-solid fa-bullseye"></i> พันธกิจ</h3>
        <ul class="list">
          <li>ให้บริการน้ำมันคุณภาพ ราคายุติธรรม</li>
          <li>นำกำไรกลับคืนสู่ชุมชนและสมาชิก</li>
          <li>พัฒนาความรู้และระบบดิจิทัลในการบริหาร</li>
        </ul>
      </div>
      <div class="card-soft">
        <h3><i class="fa-solid fa-hands-holding-heart"></i> ค่านิยม</h3>
        <p>โปร่งใส (Integrity) — ร่วมมือ (Cooperation) — รับผิดชอบ (Accountability)</p>
      </div>
    </div>
  </div>
</section>

<!-- Timeline -->
<section class="section" id="timeline">
  <div class="container">
    <h2 class="section-title">ไทม์ไลน์การเติบโต</h2>
    <div class="timeline">
      <div class="tl-item">
        <div class="tl-year">พ.ศ. 2562</div>
        <div class="tl-dot"></div>
        <div class="tl-body">
          จัดตั้งคณะทำงานและศึกษาความเป็นไปได้ ร่วมระดมทุนจากสมาชิกในชุมชน
        </div>
      </div>
      <div class="tl-item">
        <div class="tl-year">พ.ศ. 2563</div>
        <div class="tl-dot"></div>
        <div class="tl-body">
          เปิดให้บริการปั๊มน้ำมันแห่งแรก พร้อมระบบบัญชีพื้นฐานและระเบียบสหกรณ์
        </div>
      </div>
      <div class="tl-item">
        <div class="tl-year">พ.ศ. 2565</div>
        <div class="tl-dot"></div>
        <div class="tl-body">
          ขยายถังเก็บเชื้อเพลิง เพิ่มชนิดน้ำมัน และทดลองใช้ระบบดิจิทัลในการจัดการขาย
        </div>
      </div>
      <div class="tl-item">
        <div class="tl-year">พ.ศ. 2567</div>
        <div class="tl-dot"></div>
        <div class="tl-body">
          เปิดใช้ “ระบบบริหารจัดการปั๊มน้ำมัน” ครบวงจร ครอบคลุมขาย-สต๊อก-บัญชี-รายงานแบบเรียลไทม์
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Board / Committee -->
<section class="section" id="board">
  <div class="container">
    <h2 class="section-title">คณะกรรมการและที่ปรึกษา</h2>
    <div class="board">
      <div class="member">
        <div class="avatar">ปธ</div>
        <div>
          <div class="fw-700">นางสาวกมลชนก ใจดี</div>
          <div class="role">ประธานกรรมการ</div>
          <div class="muted small">กำกับดูแลภาพรวมและธรรมาภิบาล</div>
        </div>
      </div>
      <div class="member">
        <div class="avatar">นย</div>
        <div>
          <div class="fw-700">นายสมชาย มั่นคง</div>
          <div class="role">รองประธาน</div>
          <div class="muted small">ดูแลงานปฏิบัติการสถานีบริการ</div>
        </div>
      </div>
      <div class="member">
        <div class="avatar">กท</div>
        <div>
          <div class="fw-700">นางสาวศิริพร ถนอมศักดิ์</div>
          <div class="role">เหรัญญิก</div>
          <div class="muted small">การเงิน บัญชี และตรวจสอบภายใน</div>
        </div>
      </div>
      <div class="member">
        <div class="avatar">ปส</div>
        <div>
          <div class="fw-700">นายปรีชา อารี</div>
          <div class="role">ฝ่ายสต๊อก</div>
          <div class="muted small">คลังเชื้อเพลิงและความปลอดภัย</div>
        </div>
      </div>
      <div class="member">
        <div class="avatar">บช</div>
        <div>
          <div class="fw-700">นางสาวรัตนา ชาญชัย</div>
          <div class="role">บัญชี/รายงาน</div>
          <div class="muted small">สรุปผลประกอบการและรายงานสมาชิก</div>
        </div>
      </div>
      <div class="member">
        <div class="avatar">ทป</div>
        <div>
          <div class="fw-700">ที่ปรึกษา: อ. ณัฐพล</div>
          <div class="role">ที่ปรึกษา</div>
          <div class="muted small">ระบบดิจิทัลและมาตรฐานการควบคุมภายใน</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Gallery -->
<section class="section" id="gallery">
  <div class="container">
    <h2 class="section-title">ภาพกิจกรรม</h2>
    <div class="gallery">
      <img src="../assets/images/น้ำมัน.jpg" alt="บรรยากาศสถานีบริการ" />
      <img src="../assets/images/พื้นเลย.jpg" alt="การประชุมสมาชิก" />
      <img src="../assets/images/เกี่ยวกับ.jpg" alt="การอบรมเจ้าหน้าที่" />
      <img src="../assets/images/23.webp" alt="ทิวทัศน์รอบชุมชน" />
      <img src="../assets/images/น้ำมัน.jpg" alt="เติมน้ำมันบริการสมาชิก" />
      <img src="../assets/images/เกี่ยวกับ.jpg" alt="เวิร์กช็อปการใช้งานระบบ" />
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="footer">
  <div class="container footer-inner">
    <div class="footer-col">
      <div class="footer-title">เมนู</div>
      <ul class="footer-links">
        <li><a href="../index.php">หน้าแรก</a></li>
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
