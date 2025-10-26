<?php
// services.php — หน้าบริการของสหกรณ์ปั๊มน้ำมัน
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>บริการของเรา | สหกรณ์ชุมชนบ้านภูเขาทอง</title>
  <meta name="description" content="บริการครบครันจากสหกรณ์ปั๊มน้ำมัน ตั้งแต่บริการน้ำมันคุณภาพ ระบบจัดการดิจิทัล สิทธิประโยชน์สมาชิก และอื่นๆ อีกมากมาย">
  
  <!-- Fonts & Icons -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="../assets/css/coop-landing.css" />
  
  <style>
    html, body {
      font-family: 'Prompt', system-ui, -apple-system, Segoe UI, Roboto, 'Noto Sans Thai', sans-serif;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }

    /* Hero for services page */
    .services-hero {
      background: linear-gradient(135deg, var(--navy), var(--teal-ink));
      color: white;
      padding: 6rem 0 4rem;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .services-hero::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><defs><pattern id="grid" width="10" height="10" patternUnits="userSpaceOnUse"><path d="M 10 0 L 0 0 0 10" fill="none" stroke="rgba(255,255,255,0.1)" stroke-width="0.5"/></pattern></defs><rect width="100" height="100" fill="url(%23grid)"/></svg>');
      opacity: 0.3;
    }

    .services-hero-content {
      position: relative;
      z-index: 1;
    }

    .services-hero h1 {
      font-size: 3rem;
      margin-bottom: 1rem;
      font-weight: 800;
    }

    .services-hero p {
      font-size: 1.2rem;
      opacity: 0.9;
      max-width: 600px;
      margin: 0 auto;
    }

    /* Service categories */
    .service-categories {
      padding: 4rem 0;
      background: #f8f9fa;
    }

    .category-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .category-card {
      background: white;
      border-radius: 1rem;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
      transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .category-card:hover {
      transform: translateY(-10px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.15);
    }

    .category-header {
      background: linear-gradient(135deg, var(--mint), var(--gold));
      color: white;
      padding: 2rem;
      text-align: center;
    }

    .category-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
    }

    .category-title {
      font-size: 1.5rem;
      font-weight: 700;
      margin: 0;
    }

    .category-body {
      padding: 2rem;
    }

    .service-list {
      list-style: none;
      padding: 0;
      margin: 0;
    }

    .service-item {
      display: flex;
      align-items: flex-start;
      gap: 1rem;
      padding: 1rem 0;
      border-bottom: 1px solid #eee;
    }

    .service-item:last-child {
      border-bottom: none;
    }

    .service-item-icon {
      width: 40px;
      height: 40px;
      background: var(--mint);
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .service-item-content h4 {
      margin: 0 0 0.5rem 0;
      font-weight: 600;
      color: var(--navy);
    }

    .service-item-content p {
      margin: 0;
      color: var(--steel);
      line-height: 1.5;
    }

    /* Pricing section */
    .pricing-section {
      padding: 4rem 0;
      background: white;
    }

    .pricing-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .pricing-card {
      background: white;
      border: 2px solid #eee;
      border-radius: 1rem;
      padding: 2rem;
      text-align: center;
      position: relative;
      transition: border-color 0.3s ease, transform 0.3s ease;
    }

    .pricing-card:hover {
      border-color: var(--mint);
      transform: translateY(-5px);
    }

    .pricing-card.featured {
      border-color: var(--gold);
      background: linear-gradient(135deg, #fff, #fffbf0);
    }

    .pricing-badge {
      position: absolute;
      top: -10px;
      left: 50%;
      transform: translateX(-50%);
      background: var(--gold);
      color: white;
      padding: 0.5rem 1rem;
      border-radius: 20px;
      font-size: 0.9rem;
      font-weight: 600;
    }

    .pricing-title {
      font-size: 1.5rem;
      font-weight: 700;
      margin-bottom: 1rem;
      color: var(--navy);
    }

    .pricing-amount {
      font-size: 2.5rem;
      font-weight: 800;
      color: var(--mint);
      margin-bottom: 0.5rem;
    }

    .pricing-unit {
      color: var(--steel);
      margin-bottom: 2rem;
    }

    .pricing-features {
      list-style: none;
      padding: 0;
      margin: 0 0 2rem 0;
    }

    .pricing-features li {
      padding: 0.5rem 0;
      color: var(--steel);
    }

    .pricing-features li i {
      color: var(--mint);
      margin-right: 0.5rem;
    }

    .pricing-btn {
      width: 100%;
      padding: 1rem;
      background: var(--mint);
      color: white;
      border: none;
      border-radius: 0.5rem;
      font-weight: 600;
      text-decoration: none;
      display: inline-block;
      transition: background-color 0.3s ease;
    }

    .pricing-btn:hover {
      background: var(--teal-ink);
      color: white;
    }

    /* Process section */
    .process-section {
      padding: 4rem 0;
      background: #f8f9fa;
    }

    .process-steps {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 2rem;
      margin-top: 3rem;
    }

    .process-step {
      text-align: center;
      position: relative;
    }

    .process-step::after {
      content: '';
      position: absolute;
      top: 50px;
      right: -1rem;
      width: 2rem;
      height: 2px;
      background: var(--gold);
      z-index: 1;
    }

    .process-step:last-child::after {
      display: none;
    }

    .process-number {
      width: 60px;
      height: 60px;
      background: var(--gold);
      color: white;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      font-weight: 700;
      margin: 0 auto 1rem;
      position: relative;
      z-index: 2;
    }

    .process-title {
      font-size: 1.2rem;
      font-weight: 600;
      margin-bottom: 0.5rem;
      color: var(--navy);
    }

    .process-desc {
      color: var(--steel);
      line-height: 1.5;
    }

    /* FAQ section */
    .faq-section {
      padding: 4rem 0;
      background: white;
    }

    .faq-list {
      max-width: 800px;
      margin: 3rem auto 0;
    }

    .faq-item {
      border: 1px solid #eee;
      border-radius: 0.5rem;
      margin-bottom: 1rem;
      overflow: hidden;
    }

    .faq-question {
      width: 100%;
      background: #f8f9fa;
      border: none;
      padding: 1.5rem;
      text-align: left;
      font-weight: 600;
      color: var(--navy);
      cursor: pointer;
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: background-color 0.3s ease;
    }

    .faq-question:hover {
      background: #e9ecef;
    }

    .faq-question.active {
      background: var(--mint);
      color: white;
    }

    .faq-answer {
      padding: 0 1.5rem;
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease, padding 0.3s ease;
      background: white;
    }

    .faq-answer.active {
      max-height: 200px;
      padding: 1.5rem;
    }

    .faq-icon {
      transition: transform 0.3s ease;
    }

    .faq-question.active .faq-icon {
      transform: rotate(180deg);
    }

    /* CTA section */
    .services-cta {
      padding: 4rem 0;
      background: linear-gradient(135deg, var(--navy), var(--teal-ink));
      color: white;
      text-align: center;
    }

    .services-cta h2 {
      font-size: 2.5rem;
      margin-bottom: 1rem;
    }

    .services-cta p {
      font-size: 1.2rem;
      opacity: 0.9;
      margin-bottom: 2rem;
    }

    .cta-buttons {
      display: flex;
      gap: 1rem;
      justify-content: center;
      flex-wrap: wrap;
    }

    .btn-cta {
      padding: 1rem 2rem;
      border-radius: 0.5rem;
      text-decoration: none;
      font-weight: 600;
      transition: transform 0.3s ease;
    }

    .btn-cta:hover {
      transform: translateY(-2px);
    }

    .btn-primary-cta {
      background: var(--gold);
      color: var(--navy);
    }

    .btn-secondary-cta {
      background: transparent;
      color: white;
      border: 2px solid white;
    }

    .btn-secondary-cta:hover {
      background: white;
      color: var(--navy);
    }

    /* Responsive */
    @media (max-width: 768px) {
      .services-hero h1 {
        font-size: 2rem;
      }
      
      .category-grid {
        grid-template-columns: 1fr;
      }
      
      .process-step::after {
        display: none;
      }
      
      .cta-buttons {
        flex-direction: column;
        align-items: center;
      }
    }
    .navbar .btn-login {
      background-color: #20A39E; /* สีน้ำเงิน (Primary) */
      border-color: #B66D0D;
      color: #ffffff;
    }

    .navbar .btn-login:hover {
      background-color: #20A39E; /* สีน้ำเงินเข้มขึ้น */
      border-color: #B66D0D;
      color: #ffffff;
    }
  </style>
</head>

<body>
  
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
      <a href="services.php" class="active">บริการ</a>
      <a href="about.php" >ประวัติสหกรณ์</a>
      <a href="structure.php">โครงสร้างสหกรณ์</a>
      <a href="login.php" class="btn btn-login"><i class="fa-solid fa-right-to-bracket"></i> เข้าสู่ระบบ</a>
    </nav>
    <button class="menu-toggle" id="menuToggle" aria-label="เปิด/ปิดเมนู" aria-controls="menu" aria-expanded="false">
      <i class="fa-solid fa-bars"></i>
    </button>
  </div>
</header>

  <!-- Services Hero -->
  <section class="hero has-bg" style="background-image:url('../assets/images/23.webp')">
  <div class="hero-overlay"></div>
  <div class="container hero-inner">
    <div class="hero-copy">
    <h1 class="hero-title">บริการของเรา</h1>
    <p class="hero-subtitle">ครบครันทุกความต้องการด้านน้ำมันและการบริหารจัดการ พร้อมเทคโนโลยีที่ทันสมัยเพื่อประสิทธิภาพสูงสุด</p>
        </div>
    </div>
  </section>

  <!-- Service Categories -->
  <section class="service-categories">
    <div class="container">
      <h2 class="section-title" style="text-align: center;">หมวดหมู่บริการ</h2>
      
      <div class="category-grid">
        <!-- Fuel Services -->
        <div class="category-card">
          <div class="category-header">
            <div class="category-icon">
              <i class="fa-solid fa-gas-pump"></i>
            </div>
            <h3 class="category-title">บริการน้ำมัน</h3>
          </div>
          <div class="category-body">
            <ul class="service-list">
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-droplet"></i>
                </div>
                <div class="service-item-content">
                  <h4>น้ำมันดีเซล B7</h4>
                  <p>น้ำมันดีเซลคุณภาพสูง เหมาะสำหรับรถยนต์และเครื่องจักรทุกประเภท</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-leaf"></i>
                </div>
                <div class="service-item-content">
                  <h4>แก๊สโซฮอล์ 91, 95</h4>
                  <p>น้ำมันเบนซินผสมเอทานอล เป็นมิตรกับสิ่งแวดล้อม ประหยัดค่าใช้จ่าย</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-recycle"></i>
                </div>
                <div class="service-item-content">
                  <h4>E20 เอทานอล</h4>
                  <p>น้ำมันเอทานอล 20% ลดมลพิษ ราคาประหยัด เหมาะสำหรับรถที่รองรับ</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-car"></i>
                </div>
                <div class="service-item-content">
                  <h4>เบนซิน 95 ออกเทน</h4>
                  <p>น้ำมันเบนซินคุณภาพพรีเมียม สำหรับรถหรูและรถสมรรถนะสูง</p>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <!-- Technology Services -->
        <div class="category-card">
          <div class="category-header">
            <div class="category-icon">
              <i class="fa-solid fa-laptop"></i>
            </div>
            <h3 class="category-title">ระบบเทคโนโลジี</h3>
          </div>
          <div class="category-body">
            <ul class="service-list">
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-cash-register"></i>
                </div>
                <div class="service-item-content">
                  <h4>ระบบ POS ทันสมัย</h4>
                  <p>ระบบขายหน้าร้านที่รวดเร็ว แม่นยำ และใช้งานง่าย</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-chart-bar"></i>
                </div>
                <div class="service-item-content">
                  <h4>ระบบจัดการคลังสินค้า</h4>
                  <p>ติดตามสต๊อกแบบเรียลไทม์ แจ้งเตือนเมื่อสินค้าใกล้หมด</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-file-chart-line"></i>
                </div>
                <div class="service-item-content">
                  <h4>รายงานและวิเคราะห์</h4>
                  <p>รายงานยอดขาย กำไร และแนวโน้มการขายแบบละเอียด</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-cloud"></i>
                </div>
                <div class="service-item-content">
                  <h4>ระบบคลาวด์</h4>
                  <p>เข้าถึงข้อมูลได้ทุกที่ทุกเวลา ปลอดภัยและสำรองข้อมูลอัตโนมัติ</p>
                </div>
              </li>
            </ul>
          </div>
        </div>

        <!-- Member Services -->
        <div class="category-card">
          <div class="category-header">
            <div class="category-icon">
              <i class="fa-solid fa-users"></i>
            </div>
            <h3 class="category-title">สิทธิประโยชน์สมาชิก</h3>
          </div>
          <div class="category-body">
            <ul class="service-list">
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-percent"></i>
                </div>
                <div class="service-item-content">
                  <h4>ส่วนลดพิเศษ</h4>
                  <p>ส่วนลดน้ำมันสูงสุด 5-10% สำหรับสมาชิกสหกรณ์</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-coins"></i>
                </div>
                <div class="service-item-content">
                  <h4>แต้มสะสม</h4>
                  <p>สะสมแต้มทุกการซื้อ นำมาแลกส่วนลดและของรางวัล</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-gift"></i>
                </div>
                <div class="service-item-content">
                  <h4>โปรโมชั่นพิเศษ</h4>
                  <p>โปรโมชั่นและกิจกรรมพิเศษเฉพาะสมาชิกเท่านั้น</p>
                </div>
              </li>
              <li class="service-item">
                <div class="service-item-icon">
                  <i class="fa-solid fa-calendar"></i>
                </div>
                <div class="service-item-content">
                  <h4>กิจกรรมสมาชิก</h4>
                  <p>งานประชุมใหญ่ อบรม และกิจกรรมสร้างความสัมพันธ์</p>
                </div>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Process -->
  <section class="process-section">
    <div class="container">
      <h2 class="section-title" style="text-align: center;">ขั้นตอนการใช้บริการ</h2>
      <p style="text-align: center; color: var(--steel);">เริ่มต้นใช้บริการง่ายๆ เพียง 4 ขั้นตอน</p>
      
      <div class="process-steps">
        <div class="process-step">
          <div class="process-number">1</div>
          <h4 class="process-title">สมัครสมาชิก</h4>
          <p class="process-desc">กรอกข้อมูลและสมัครสมาชิกออนไลน์หรือที่ปั๊มน้ำมัน</p>
        </div>
        
        <div class="process-step">
          <div class="process-number">2</div>
          <h4 class="process-title">ยืนยันตัวตน</h4>
          <p class="process-desc">แสดงบัตรประชาชนและเอกสารยืนยันตัวตนที่เกี่ยวข้อง</p>
        </div>
        
        <div class="process-step">
          <div class="process-number">3</div>
          <h4 class="process-title">รับบัตรสมาชิก</h4>
          <p class="process-desc">รับบัตรสมาชิกและคู่มือการใช้งานระบบต่างๆ</p>
        </div>
        
        <div class="process-step">
          <div class="process-number">4</div>
          <h4 class="process-title">เริ่มใช้บริการ</h4>
          <p class="process-desc">เติมน้ำมันและใช้สิทธิประโยชน์ต่างๆ ได้ทันที</p>
        </div>
      </div>
    </div>
  </section>

  <!-- FAQ -->
  <section class="faq-section">
    <div class="container">
      <h2 class="section-title" style="text-align: center;">คำถามที่พบบ่อย</h2>
      
      <div class="faq-list">
        <div class="faq-item">
          <button class="faq-question">
            <span>การสมัครสมาชิกมีเงื่อนไขอย่างไร?</span>
            <i class="fa-solid fa-chevron-down faq-icon"></i>
          </button>
          <div class="faq-answer">
            <p>ต้องเป็นบุคคลที่มีอายุ 18 ปีขึ้นไป มีภูมิลำเนาในพื้นที่ใกล้เคียง และยินยอมตามเงื่อนไขของสหกรณ์</p>
          </div>
        </div>
        
        <div class="faq-item">
          <button class="faq-question">
            <span>ส่วนลดสมาชิกใช้ได้กับน้ำมันทุกชนิดหรือไม่?</span>
            <i class="fa-solid fa-chevron-down faq-icon"></i>
          </button>
          <div class="faq-answer">
            <p>ส่วนลดใช้ได้กับน้ำมันทุกชนิดที่จำหน่ายในปั๊ม ยกเว้นสินค้าส่งเสริมการขายและน้ำมันราคาพิเศษ</p>
          </div>
        </div>
        
        <div class="faq-item">
          <button class="faq-question">
            <span>แต้มสะสมหมดอายุหรือไม่?</span>
            <i class="fa-solid fa-chevron-down faq-icon"></i>
          </button>
          <div class="faq-answer">
            <p>แต้มสะสมมีอายุ 1 ปี นับจากวันที่ได้รับ หากไม่ใช้จะหมดอายุและไม่สามารถขอคืนได้</p>
          </div>
        </div>
        
        <div class="faq-item">
          <button class="faq-question">
            <span>สามารถยกเลิกสมาชิกภาพได้หรือไม่?</span>
            <i class="fa-solid fa-chevron-down faq-icon"></i>
          </button>
          <div class="faq-answer">
            <p>สามารถยกเลิกได้ตลอดเวลา โดยแจ้งล่วงหน้า 30 วัน แต้มสะสมและสิทธิประโยชน์จะหมดไปทันที</p>
          </div>
        </div>
        
        <div class="faq-item">
          <button class="faq-question">
            <span>มีบริการช่วยเหลือฉุกเฉินหรือไม่?</span>
            <i class="fa-solid fa-chevron-down faq-icon"></i>
          </button>
          <div class="faq-answer">
            <p>มีบริการช่วยเหลือฉุกเฉิน 24 ชั่วโมง สำหรับสมาชิกพรีเมียมและโกลด์ รวมถึงบริการลากจูง</p>
          </div>
        </div>
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
    <div class="copyright">© <?php echo date('Y'); ?> สหกรณ์ชุมชนบ้านภูเขาทอง - บริการครบครัน ใส่ใจคุณภาพ</div>
  </footer>

  <!-- Scroll to top -->
  <button class="scroll-top" id="scrollTop" aria-label="เลื่อนไปบนสุด"><i class="fa-solid fa-chevron-up"></i></button>

  <script>
    // Menu toggle
    const menuToggle = document.getElementById('menuToggle');
    const menu = document.getElementById('menu');
    menuToggle.addEventListener('click', () => {
      const opened = menu.classList.toggle('active');
      menuToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
    });

    // Scroll to top
    const btnTop = document.getElementById('scrollTop');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 300) btnTop.classList.add('visible'); 
      else btnTop.classList.remove('visible');
    });
    btnTop.addEventListener('click', () => window.scrollTo({top: 0, behavior: 'smooth'}));

    // FAQ functionality
    document.querySelectorAll('.faq-question').forEach(button => {
      button.addEventListener('click', () => {
        const isActive = button.classList.contains('active');
        
        // Close all other FAQ items
        document.querySelectorAll('.faq-question').forEach(btn => {
          btn.classList.remove('active');
          btn.nextElementSibling.classList.remove('active');
        });
        
        // Toggle current item
        if (!isActive) {
          button.classList.add('active');
          button.nextElementSibling.classList.add('active');
        }
      });
    });

    // Smooth animations on scroll
    const observerOptions = {
      threshold: 0.1,
      rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, observerOptions);

    // Observe cards and sections
    document.querySelectorAll('.category-card, .pricing-card, .process-step').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(30px)';
      el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(el);
    });

    // Add staggered animation delays
    document.querySelectorAll('.category-card').forEach((card, index) => {
      card.style.transitionDelay = `${index * 0.1}s`;
    });

    document.querySelectorAll('.pricing-card').forEach((card, index) => {
      card.style.transitionDelay = `${index * 0.1}s`;
    });

    document.querySelectorAll('.process-step').forEach((step, index) => {
      step.style.transitionDelay = `${index * 0.1}s`;
    });
  </script>
</body>
</html>