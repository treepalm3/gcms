<?php
// index.php — Enhanced Landing Page for Cooperative Gas Management
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>สหกรณ์ชุมชนบ้านภูเขาทอง — ระบบปั๊มน้ำมัน</title>
  <meta name="description" content="ข่าว กิจกรรม ประกาศ ติดต่อ และทางเข้าใช้งานระบบบริหารจัดการปั๊มน้ำมันสำหรับสหกรณ์ชุมชนบ้านภูเขาทอง">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/css/coop-landing.css" />
  
  <style>
    html, body {
      font-family: 'Prompt', system-ui, -apple-system, Segoe UI, Roboto, 'Noto Sans Thai', sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* Enhanced animations */
    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @keyframes slideInLeft {
      from { opacity: 0; transform: translateX(-30px); }
      to { opacity: 1; transform: translateX(0); }
    }

    @keyframes slideInRight {
      from { opacity: 0; transform: translateX(30px); }
      to { opacity: 1; transform: translateX(0); }
    }

    @keyframes pulse {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.05); }
    }

    .animate-fade-in-up {
      animation: fadeInUp 0.8s ease-out forwards;
      opacity: 0;
    }

    .animate-slide-in-left {
      animation: slideInLeft 0.8s ease-out forwards;
      opacity: 0;
    }

    .animate-slide-in-right {
      animation: slideInRight 0.8s ease-out forwards;
      opacity: 0;
    }

    /* Enhanced hero section */
    .hero-enhanced {
      position: relative;
      overflow: hidden;
    }

    .hero-enhanced::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(45deg, rgba(32, 163, 158, 0.1), rgba(204, 164, 59, 0.1));
      animation: pulse 4s ease-in-out infinite;
      z-index: -1;
    }

    /* Services section */
    .services-enhanced {
      padding: 4rem 0;
      background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    }

    .services-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin-top: 2rem;
    }

    .service-card {
      background: white;
      border-radius: 1rem;
      padding: 2rem;
      text-align: center;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      border-top: 4px solid var(--mint);
    }

    .service-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    .service-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 1.5rem;
      background: linear-gradient(135deg, var(--mint), var(--gold));
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      color: white;
    }

    /* Statistics section */
    .stats-section {
      padding: 4rem 0;
      background: var(--navy);
      color: white;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 2rem;
      text-align: center;
    }

    .stat-item {
      padding: 1.5rem;
    }

    .stat-number {
      font-size: 3rem;
      font-weight: 800;
      color: var(--gold);
      display: block;
      margin-bottom: 0.5rem;
    }

    .stat-label {
      font-size: 1.1rem;
      opacity: 0.9;
    }

    /* Testimonials */
    .testimonials {
      padding: 4rem 0;
      background: white;
    }

    .testimonial-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-top: 2rem;
    }

    .testimonial-card {
      background: #f8f9fa;
      border-radius: 1rem;
      padding: 2rem;
      position: relative;
      border-left: 4px solid var(--gold);
    }

    .testimonial-quote {
      font-size: 1.1rem;
      line-height: 1.6;
      margin-bottom: 1.5rem;
      font-style: italic;
    }

    .testimonial-author {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .author-avatar {
      width: 50px;
      height: 50px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--mint), var(--gold));
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 700;
    }

    .author-info h5 {
      margin: 0;
      font-weight: 600;
    }

    .author-info span {
      color: var(--steel);
      font-size: 0.9rem;
    }

    /* Enhanced cards with hover effects */
    .card-enhanced {
      transition: transform 0.3s ease, box-shadow 0.3s ease;
      cursor: pointer;
    }

    .card-enhanced:hover {
      transform: translateY(-5px);
      box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    }

    .card-enhanced .card-image {
      width: 100%;
      height: 200px;
      object-fit: cover;
      transition: transform 0.3s ease;
    }

    .card-enhanced:hover .card-image {
      transform: scale(1.05);
    }

    /* Call to Action section */
    .cta-section {
      padding: 4rem 0;
      background: linear-gradient(135deg, var(--mint), var(--gold));
      color: white;
      text-align: center;
    }

    .cta-content h2 {
      font-size: 2.5rem;
      margin-bottom: 1rem;
    }

    .cta-content p {
      font-size: 1.2rem;
      margin-bottom: 2rem;
      opacity: 0.9;
    }

    .cta-buttons {
      display: flex;
      gap: 1rem;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn-white {
      background: white;
      color: var(--navy);
      padding: 1rem 2rem;
      border-radius: 0.5rem;
      text-decoration: none;
      font-weight: 600;
      transition: transform 0.3s ease;
    }

    .btn-white:hover {
      transform: translateY(-2px);
      color: var(--navy);
    }

    .btn-outline-white {
      border: 2px solid white;
      background: transparent;
      color: white;
      padding: 1rem 2rem;
      border-radius: 0.5rem;
      text-decoration: none;
      font-weight: 600;
      transition: all 0.3s ease;
    }

    .btn-outline-white:hover {
      background: white;
      color: var(--navy);
      transform: translateY(-2px);
    }

    /* Enhanced news list */
    .news-item {
      transition: background-color 0.3s ease;
      border-radius: 0.5rem;
      margin: 0.25rem 0;
    }

    .news-item:hover {
      background-color: rgba(32, 163, 158, 0.05);
    }

    /* Floating elements */
    .floating-icons {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      pointer-events: none;
      overflow: hidden;
    }

    .floating-icon {
      position: absolute;
      opacity: 0.1;
      animation: float 6s ease-in-out infinite;
    }

    @keyframes float {
      0%, 100% { transform: translateY(0px) rotate(0deg); }
      50% { transform: translateY(-20px) rotate(180deg); }
    }

    .floating-icon:nth-child(1) { top: 20%; left: 10%; animation-delay: 0s; }
    .floating-icon:nth-child(2) { top: 60%; right: 10%; animation-delay: 2s; }
    .floating-icon:nth-child(3) { bottom: 20%; left: 20%; animation-delay: 4s; }

    /* Responsive improvements */
    @media (max-width: 768px) {
      .cta-content h2 { font-size: 2rem; }
      .cta-buttons { flex-direction: column; align-items: center; }
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .stat-number { font-size: 2rem; }
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
    <a class="brand" href="#home" aria-label="หน้าแรก">
      <img src="assets/images/ต.jpg" alt="ตราสหกรณ์" class="brand-mark" />
      <div class="brand-text">
        <div class="brand-name">สหกรณ์ชุมชนบ้านภูเขาทอง</div>
        <div class="brand-sub">ระบบบริหารจัดการปั๊มน้ำมัน</div>
      </div>
    </a>
    <nav class="menu" id="menu" aria-label="เมนูหลัก">
      <a href="index.php" class="active">หน้าแรก</a>
      <a href="index/services.php" >บริการ</a>
      <a href="index/about.php" >ประวัติสหกรณ์</a>
      <a href="index/structure.php">โครงสร้างสหกรณ์</a>
      <a href="index/login.php" class="btn btn-login"><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ</a>
    </nav>
    <button class="menu-toggle" id="menuToggle" aria-label="เปิด/ปิดเมนู" aria-controls="menu" aria-expanded="false">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</header>

<!-- Hero -->
<section class="hero has-bg hero-enhanced" id="home" aria-label="ภาพรวม"
         style="background-image:url('assets/images/23.webp')">
  <div class="hero-overlay"></div>
  <div class="floating-icons">
    <i class="floating-icon fa-solid fa-gas-pump" style="font-size: 3rem;"></i>
    <i class="floating-icon fa-solid fa-handshake" style="font-size: 2.5rem;"></i>
    <i class="floating-icon fa-solid fa-leaf" style="font-size: 2rem;"></i>
  </div>
  <div class="container hero-inner">
    <div class="hero-copy animate-slide-in-left">
      <h1 class="hero-title">สหกรณ์ปั๊มน้ำมันชุมชน</h1>
      <p class="hero-subtitle">บริการน้ำมันคุณภาพ จัดการปั๊มน้ำมันได้ครบครัน ทันสมัย และใช้งานง่าย เพื่อความโปร่งใสและประสิทธิภาพในการดำเนินงานของสหกรณ์ รองรับการทำงานแบบดิจิทัลยุค 4.0</p>
      <div class="hero-actions">
        <a href="index/login.php" class="btn btn-primary"><i class="fa-solid fa-gas-pump"></i> เข้าสู่ระบบ</a>
        <a href="index/services.php" class="btn btn-outline-white"><i class="fa-solid fa-info-circle"></i> เรียนรู้เพิ่มเติม</a>
      </div>
    </div>

    <div class="hero-art animate-slide-in-right" aria-hidden="true">
      <div class="hero-card">
      <div class="hero-kpi">
          <div class="kpi"><div class="kpi-num">40</div><div class="kpi-label">สมาชิกทั้งหมด</div></div>
          <div class="kpi"><div class="kpi-num">9</div><div class="kpi-label">กิจกรรม</div></div>
          <div class="kpi"><div class="kpi-num">4</div><div class="kpi-label">ศูนย์ประสานงาน</div></div>
        </div>
        <div class="hero-badges">
          <span class="pill"><i class="fa-solid fa-shield"></i> โปร่งใส</span>
          <span class="pill"><i class="fa-solid fa-leaf"></i> ชุมชน</span>
          <span class="pill"><i class="fa-solid fa-mobile-screen"></i> ใช้งานง่าย</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- News Section -->
<section class="downloads" id="downloads">
  <div class="container">
    <div class="split-col">
      <h2 class="section-title mt">ข่าวประชาสัมพันธ์</h2>
      <ul class="list list-news">
        <li class="news-item"><a href="#"><span class="dot"></span> ปรับราคาน้ำมันตามราคาตลาดโลก ประจำสัปดาห์</a><time datetime="2025-01-22">22 ม.ค. 2568</time></li>
        <li class="news-item"><a href="#"><span class="dot"></span> เปิดบริการชำระเงินผ่าน QR Code PromptPay</a><time datetime="2025-01-20">20 ม.ค. 2568</time></li>
        <li class="news-item"><a href="#"><span class="dot"></span> โครงการเพื่อนชวนเพื่อน รับคูปองลดราคา</a><time datetime="2025-01-18">18 ม.ค. 2568</time></li>
        <li class="news-item"><a href="#"><span class="dot"></span> คู่มือการใช้งานระบบสมาชิกออนไลน์</a><time datetime="2025-01-15">15 ม.ค. 2568</time></li>
        <li class="news-item"><a href="#"><span class="dot"></span> ประกาศหยุดบริการเพื่อบำรุงรักษาระบบ</a><time datetime="2025-01-12">12 ม.ค. 2568</time></li>
      </ul>
    </div>
  </div>
</section>

<!-- Activities / News -->
<section class="news" id="news">
  <div class="container">
    <h2 class="section-title">กิจกรรม</h2>
    <div class="cards">
      <article class="card card-enhanced">
        <div class="thumb">
          <img src="assets/images/น้ำมัน.jpg" alt="กิจกรรมประชุมใหญ่" class="card-image" />
        </div>
        <div class="card-body">
          <h3 class="card-title">วันประชุมใหญ่สามัญประจำปี 2568</h3>
          <p class="card-text">เชิญชวนสมาชิกทุกท่านเข้าร่วมและรับฟังรายงานผลการดำเนินงาน พร้อมรับสิทธิประโยชน์ใหม่</p>
          <a class="card-link" href="#">อ่านต่อ <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      </article>
      
      <article class="card card-enhanced">
        <div class="thumb">
          <img src="assets/images/พื้นเลย.jpg" alt="กิจกรรมโครงการออม" class="card-image" />
        </div>
        <div class="card-body">
          <h3 class="card-title">โครงการส่งเสริมการออมเงิน</h3>
          <p class="card-text">สะสมหุ้น/ออมทรัพย์ รับสิทธิประโยชน์และดอกเบี้ยพิเศษ ได้รับส่วนลดน้ำมันสูงสุด 10%</p>
          <a class="card-link" href="#">อ่านต่อ <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      </article>
      
      <article class="card card-enhanced">
        <div class="thumb">
          <img src="assets/images/เกี่ยวกับ.jpg" alt="กิจกรรมอบรมแอป" class="card-image" />
        </div>
        <div class="card-body">
          <h3 class="card-title">อบรมการใช้งานระบบดิจิทัล</h3>
          <p class="card-text">เรียนรู้การใช้งานระบบจัดการปั๊มน้ำมันใหม่ เพื่อความสะดวกและมีประสิทธิภาพ</p>
          <a class="card-link" href="#">อ่านต่อ <i class="fa-solid fa-arrow-right"></i></a>
        </div>
      </article>
    </div>
  </div>
</section>

<!-- Testimonials -->
<section class="testimonials">
  <div class="container">
    <h2 class="section-title" style="text-align: center; margin-bottom: 1rem;">เสียงจากสมาชิก</h2>
    <p style="text-align: center; color: var(--steel); margin-bottom: 3rem;">ความประทับใจจากผู้ใช้บริการ</p>
    
    <div class="testimonial-grid">
      <div class="testimonial-card animate-fade-in-up">
        <div class="testimonial-quote">
          "ระบบจัดการใหม่ทำให้การทำงานสะดวกมาก ลดเวลาในการจัดทำรายงาน และข้อมูลแม่นยำขึ้น"
        </div>
        <div class="testimonial-author">
          <div class="author-avatar">ส</div>
          <div class="author-info">
            <h5>สมชาย จริงใจ</h5>
            <span>พนักงานปั๊มน้ำมัน</span>
          </div>
        </div>
      </div>
      
      <div class="testimonial-card animate-fade-in-up">
        <div class="testimonial-quote">
          "บริการดี ราคาเป็นธรรม ได้รับสิทธิประโยชน์เป็นสมาชิกอีกด้วย แนะนำให้เพื่อนบ้านมาใช้บริการ"
        </div>
        <div class="testimonial-author">
          <div class="author-avatar">พ</div>
          <div class="author-info">
            <h5>พิมพ์ใจ สุขใส</h5>
            <span>สมาชิกสหกรณ์</span>
          </div>
        </div>
      </div>
      
      <div class="testimonial-card animate-fade-in-up">
        <div class="testimonial-quote">
          "การบริหารจัดการโปร่งใส ตรวจสอบได้ ทำให้เราเชื่อมั่นในการดำเนินงานของสหกรณ์"
        </div>
        <div class="testimonial-author">
          <div class="author-avatar">ว</div>
          <div class="author-info">
            <h5>วิชัย เก่งกาจ</h5>
            <span>กรรมการสหกรณ์</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>


<!-- Contact -->
<section class="contact" id="contact">
  <div class="container contact-inner">
    <div class="contact-left animate-slide-in-left">
      <h2 class="section-title">ติดต่อสหกรณ์</h2>
      <p class="lead">บ้านภูเขาทอง หมู่ 5 และหมู่ 9 ต.คำพอุง อ.โพธิ์ชัย จ.ร้อยเอ็ด 46000</p>
      <div class="contact-list">
        <div><i class="fa-solid fa-phone"></i> โทร : <a href="tel:043123456">043-123-456</a></div>
        <div><i class="fa-brands fa-line"></i> LINE ID : <a href="#">@phukhaothong</a></div>
        <div><i class="fa-regular fa-envelope"></i> อีเมล : <a href="mailto:info@phukhaothong-coop.th">info@phukhaothong-coop.th</a></div>
        <div><i class="fa-solid fa-clock"></i> เวลาทำการ : 06:00 - 22:00 ทุกวัน</div>
      </div>
    </div>
    <div class="contact-right animate-slide-in-right">
      <iframe title="แผนที่"
        src="https://maps.google.com/maps?q=%E0%B8%9A%E0%B9%89%E0%B8%B2%E0%B8%99%E0%B8%A0%E0%B8%B9%E0%B9%80%E0%B8%82%E0%B8%B2%E0%B8%97%E0%B8%AD%E0%B8%87%20%E0%B8%95.%E0%B8%84%E0%B8%B3%E0%B8%9E%E0%B8%AD%E0%B8%B8%E0%B8%87%20%E0%B8%AD.%E0%B9%82%E0%B8%9E%E0%B8%98%E0%B8%B4%E0%B9%8C%E0%B8%8A%E0%B8%B1%E0%B8%A2%20%E0%B8%A3%E0%B9%89%E0%B8%AD%E0%B8%A2%E0%B9%80%E0%B8%AD%E0%B9%87%E0%B8%94%2046000&t=m&z=14&output=embed"
        loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="footer">
<div class="container footer-inner">
    <div class="footer-col">
      <div class="footer-title">เมนู</div>
      <ul class="footer-links">
        <li><a href="#home">หน้าแรก</a></li>
        <li><a href="index/services.php">บริการ</a></li>
        <li><a href="index/about.php">ประวัติสหกรณ์</a></li>
        <li><a href="#services">โครงสร้างสหกรณ์</a></li>
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
  <div class="copyright">© <?php echo date('Y'); ?> สหกรณ์ชุมชนบ้านภูเขาทอง - ระบบบริหารจัดการปั๊มน้ำมัน</div>
</footer>

<!-- Scroll to top -->
<button class="scroll-top" id="scrollTop" aria-label="เลื่อนไปบนสุด"><i class="fa-solid fa-chevron-up"></i></button>

<script>
  // Enhanced menu toggle
  const menuToggle = document.getElementById('menuToggle');
  const menu = document.getElementById('menu');
  menuToggle.addEventListener('click', () => {
    const opened = menu.classList.toggle('active');
    menuToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
  });

  // Close menu on item click (mobile)
  menu.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', () => { 
      menu.classList.remove('active'); 
      menuToggle.setAttribute('aria-expanded', 'false'); 
    });
  });

  // Smooth scroll with offset for fixed navbar
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      const id = a.getAttribute('href');
      if (id.length > 1) { 
        e.preventDefault(); 
        const target = document.querySelector(id);
        if (target) {
          const offset = 80; // navbar height
          const elementPosition = target.getBoundingClientRect().top;
          const offsetPosition = elementPosition + window.pageYOffset - offset;
          
          window.scrollTo({
            top: offsetPosition,
            behavior: 'smooth'
          });
        }
      }
    });
  });

  // Scroll to top
  const btnTop = document.getElementById('scrollTop');
  window.addEventListener('scroll', () => {
    if (window.scrollY > 300) btnTop.classList.add('visible'); 
    else btnTop.classList.remove('visible');
  });
  btnTop.addEventListener('click', () => window.scrollTo({top: 0, behavior: 'smooth'}));

  // Animated counters
  function animateCounters() {
    const counters = document.querySelectorAll('.stat-number');
    
    counters.forEach(counter => {
      const target = parseInt(counter.getAttribute('data-target'));
      const increment = target / 100;
      let current = 0;
      
      const updateCounter = () => {
        if (current < target) {
          current += increment;
          counter.textContent = Math.floor(current).toLocaleString();
          requestAnimationFrame(updateCounter);
        } else {
          counter.textContent = target.toLocaleString();
        }
      };
      
      updateCounter();
    });
  }

  // Intersection Observer for animations
  const observerOptions = {
    threshold: 0.1,
    rootMargin: '0px 0px -50px 0px'
  };

  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if (entry.isIntersecting) {
        entry.target.style.animationDelay = '0s';
        entry.target.style.opacity = '1';
        
        // Trigger counter animation for stats section
        if (entry.target.closest('.stats-section')) {
          animateCounters();
        }
      }
    });
  }, observerOptions);

  // Observe animated elements
  document.querySelectorAll('.animate-fade-in-up, .animate-slide-in-left, .animate-slide-in-right').forEach(el => {
    observer.observe(el);
  });

  // Add staggered animation delays for service cards
  document.querySelectorAll('.service-card').forEach((card, index) => {
    card.style.animationDelay = `${index * 0.1}s`;
  });

  // Enhanced card hover effects
  document.querySelectorAll('.card-enhanced').forEach(card => {
    card.addEventListener('mouseenter', function() {
      this.style.transform = 'translateY(-10px) scale(1.02)';
    });
    
    card.addEventListener('mouseleave', function() {
      this.style.transform = 'translateY(0) scale(1)';
    });
  });
</script>
</body>
</html>