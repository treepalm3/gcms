<?php
session_start();
require_once __DIR__ . '/../config/db.php';
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$err = isset($_GET['err']) ? htmlspecialchars($_GET['err']) : '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>เข้าสู่ระบบ — สหกรณ์ปั๊มน้ำบ้านภูเขาทอง</title>
  <meta name="description" content="เข้าสู่ระบบสำหรับบริหารจัดการปั๊มน้ำมันของสหกรณ์ชุมชนบ้านภูเขาทอง">
  
  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700;800&family=Sarabun:wght@300;400;500;600&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/login.css" />
  
  <style>
    /* ===== Login Theme — Palette =====
       #E2D4B7 #513F32 #68727A #36535E #212845 #CCA43B #20A39E #B66D0D #3D2B1F
    */
    :root{
      --sand:#E2D4B7;
      --cafe:#513F32;
      --steel:#68727A;
      --teal:#36535E;
      --navy:#212845;
      --gold:#CCA43B;
      --mint:#20A39E;
      --amber:#B66D0D;
      --choco:#3D2B1F;

      --bg:#F6F1E8;                         /* ฉากหลังโทนอุ่น */
      --card:#ffffff;                        /* การ์ด */
      --card-border:#E8E0D2;
      --shadow:0 10px 28px rgba(33,40,69,.12);
      --hover-shadow:0 16px 40px rgba(33,40,69,.18);
      --radius:14px;
      --ease:all .28s ease;
    }

    /* Base */
    *{margin:0;padding:0;box-sizing:border-box}
    html,body{height:100%}
    body{
      font-family:'Prompt',system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;
      background:
        radial-gradient(1200px 600px at 10% -10%, rgba(204,164,59,.18), transparent 60%),
        radial-gradient(900px 500px at 110% 0%, rgba(32,163,158,.12), transparent 55%),
        var(--bg);
      color:var(--navy);
      min-height:100dvh;
      display:flex;
      flex-direction:column;             /* ให้ footer ดันไปท้ายสุด */
      align-items:center;
      justify-content:center;
      padding:24px;
    }

    /* Logo/Brand Section */
    .auth-brand {
      text-align: center;
      margin-bottom: 2rem;
    }

    .auth-logo {
      width: 80px;
      height: 80px;
      margin: 0 auto 1rem;
      background: linear-gradient(135deg, var(--mint), var(--teal));
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: var(--shadow);
      animation: logoFloat 3s ease-in-out infinite;
    }

    .auth-logo i {
      font-size: 2rem;
      color: white;
    }

    @keyframes logoFloat {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-8px); }
    }

    .company-name {
      font-size: 0.9rem;
      color: var(--steel);
      font-weight: 500;
      margin-top: 0.5rem;
    }

    /* Card */
    .auth{
      width:100%;
      max-width:520px;
      background:var(--card);
      border:1px solid var(--card-border);
      border-radius:var(--radius);
      box-shadow:var(--shadow);
      padding:32px 24px 24px;
      animation: cardSlideUp 0.6s ease-out;
    }

    @keyframes cardSlideUp {
      from {
        opacity: 0;
        transform: translateY(30px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .auth-title{
      font-size:1.65rem; font-weight:800; text-align:center; margin-bottom:.25rem;
      background:linear-gradient(90deg,var(--navy),var(--teal));
      -webkit-background-clip:text; -webkit-text-fill-color:transparent;
      background-clip:text;
    }
    .auth-subtitle{ text-align:center; color:var(--steel); margin-bottom:24px; font-size: 1rem; }

    /* Alerts */
    .alert{
      display:flex;gap:.75rem;align-items:center;padding:12px 16px;border-radius:12px;margin-bottom:16px;
      animation: alertSlideIn 0.4s ease-out;
    }
    .alert-error{background:#fff5f5;border:1px solid #fed7d7;color:#c53030}
    .alert-error i { color: #e53e3e; }

    @keyframes alertSlideIn {
      from {
        opacity: 0;
        transform: translateX(-20px);
      }
      to {
        opacity: 1;
        transform: translateX(0);
      }
    }

    /* Form */
    .form{margin-top:8px}
    .form-group{margin-bottom:1.25rem}
    .form-group label{
      font-weight:600;color:var(--cafe);display:block;margin-bottom:.6rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .input{position:relative}
    .input .input-icon{
      position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--steel);
      font-size: 1.1rem;
      transition: var(--ease);
    }
    .input input{
      width:100%; padding:14px 14px 14px 44px; font-size:1rem;
      border:2px solid #e2e8f0; border-radius:12px; background:#fbfbfb; color:var(--navy);
      transition:var(--ease);
    }
    .input input::placeholder{color:var(--steel)}
    .input input:focus{
      outline:none; border-color:var(--mint);
      box-shadow:0 0 0 3px rgba(32,163,158,.15);
      background:#fff;
    }
    .input input:focus + .input-icon {
      color: var(--mint);
    }

    /* toggle password */
    .toggle-pass{
      position:absolute; right:12px; top:50%; transform: translateY(-50%); 
      border:0; background:transparent; cursor:pointer;
      padding: 8px;
      border-radius: 6px;
      transition: var(--ease);
    }
    .toggle-pass i{ color:var(--steel); transition:var(--ease) }
    .toggle-pass:hover { background: rgba(0,0,0,0.05); }
    .toggle-pass:hover i{ color:var(--teal) }

    /* Role Selection */
    .role-selection{
      display:grid; 
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); 
      gap:12px; 
      margin-top:.75rem;
    }
    .role-selection input{ display:none }
    .role-selection label{
      cursor:pointer; user-select:none;
      background:linear-gradient(180deg,#fff,rgba(226,212,183,.35));
      border:2px solid var(--card-border);
      color:var(--teal);
      padding:16px 12px; border-radius:12px; font-weight:600;
      transition:var(--ease); box-shadow:0 2px 8px rgba(33,40,69,.06);
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      min-height: 80px;
      justify-content: center;
    }

    .role-selection label i {
      font-size: 1.5rem;
      transition: var(--ease);
    }

    .role-selection label span {
      font-size: 0.85rem;
      line-height: 1.2;
    }

    .role-selection label:hover{
      transform:translateY(-2px);
      box-shadow:var(--hover-shadow);
      border-color: var(--mint);
    }
    
    .role-selection input:checked + label{
      background:linear-gradient(135deg, rgba(32,163,158,.15), rgba(204,164,59,.15));
      border-color:var(--mint); color:var(--navy);
      transform: translateY(-2px);
    }

    .role-selection input:checked + label i {
      color: var(--mint);
      transform: scale(1.1);
    }

    /* Form Actions */
    .form-row {
      display: flex;
      justify-content: flex-end;
      margin-bottom: 1.5rem;
    }

    /* Buttons */
    .btn{
      width:100%; padding:14px 20px; font-size:1.05rem; font-weight:700;
      border-radius:12px; cursor:pointer; transition:var(--ease);
      display:inline-flex; gap:.6rem; align-items:center; justify-content:center;
      box-shadow:var(--shadow);
      border: none;
      text-decoration: none;
      position: relative;
      overflow: hidden;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      transition: left 0.5s;
    }

    .btn:hover::before {
      left: 100%;
    }

    /* หลัก: ทอง -> อำพัน */
    .btn-primary{
      background:linear-gradient(135deg,var(--gold),var(--amber));
      color:#1a1a1a; border:1px solid rgba(61,43,31,.2);
    }
    .btn-primary:hover{
      filter:brightness(1.08);
      transform:translateY(-2px);
      box-shadow: var(--hover-shadow);
      color:#111;
    }

    .btn-primary:active {
      transform: translateY(0px);
    }

    /* รอง: ขอบสีเขียวน้ำทะเล */
    .btn-outline{
      background:transparent; border:2px solid var(--teal); color:var(--teal);
      box-shadow: 0 4px 12px rgba(54,83,94,.15);
    }
    .btn-outline:hover{
      background:var(--teal); color:#fff;
      transform:translateY(-2px);
    }

    /* Links */
    .link-muted{
      color:var(--gold); text-decoration:none; font-weight:600;
      font-size: 0.9rem;
      transition: var(--ease);
    }
    .link-muted:hover{ 
      color:var(--amber); 
      text-decoration:underline;
    }

    /* Divider */
    .divider{ 
      margin:24px 0; 
      position: relative;
      text-align: center;
    }

    .divider::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 0;
      right: 0;
      height: 1px;
      background: var(--card-border);
    }

    .divider::after {
      content: 'หรือ';
      background: var(--card);
      padding: 0 16px;
      color: var(--steel);
      font-size: 0.875rem;
      position: relative;
    }

    /* Loading State */
    .btn.loading {
      pointer-events: none;
      opacity: 0.8;
    }

    .btn.loading::after {
      content: '';
      width: 18px;
      height: 18px;
      border: 2px solid transparent;
      border-top: 2px solid currentColor;
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-left: 8px;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }

    /* Footer — ดันไปท้ายสุดเสมอ */
    footer{
      margin-top:auto;
      width:100%; text-align:center; padding:16px 12px;
      background:var(--navy); color:var(--sand);
      border-top:1px solid rgba(255,255,255,.08);
      font-size: 0.875rem;
    }
    footer .copyright{ color:var(--gold); font-weight:600 }

    /* Responsive */
    @media (max-width:640px){
      .auth{padding:24px 18px; margin: 12px;}
      .auth-title{font-size:1.45rem}
      .role-selection {
        grid-template-columns: repeat(2, 1fr);
      }
      .role-selection label {
        min-height: 70px;
        padding: 12px 8px;
      }
      .role-selection label span {
        font-size: 0.8rem;
      }
    }

    @media (max-width:480px){
      .role-selection {
        grid-template-columns: 1fr;
      }
      body { padding: 16px; }
    }

    /* Keep this: remove underline on outline-btn anchors (ถ้ามาจาก <a>) */
    a.btn.btn-outline{ text-decoration:none }

    /* Focus states for accessibility */
    .role-selection input:focus + label {
      outline: 2px solid var(--mint);
      outline-offset: 2px;
    }

    /* Additional animations */
    .form-group {
      animation: fadeInUp 0.5s ease-out backwards;
    }

    .form-group:nth-child(1) { animation-delay: 0.1s; }
    .form-group:nth-child(2) { animation-delay: 0.2s; }
    .form-group:nth-child(3) { animation-delay: 0.3s; }
    .form-group:nth-child(4) { animation-delay: 0.4s; }

    @keyframes fadeInUp {
      from {
        opacity: 0;
        transform: translateY(20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }
  </style>
</head>

<body>
  <div class="auth-brand">
    <div class="auth-logo">
      <i class="fas fa-gas-pump"></i>
    </div>
    <div class="company-name">สหกรณ์ปั๊มน้ำบ้านภูเขาทอง</div>
  </div>

  <main class="auth" role="form" aria-labelledby="loginTitle">
    <h1 id="loginTitle" class="auth-title">เข้าสู่ระบบ</h1>
    <p class="auth-subtitle">ระบบบริหารจัดการปั๊มน้ำมัน</p>

    <?php if ($err): ?>
    <div class="alert alert-error" role="alert">
      <i class="fas fa-exclamation-triangle"></i>
      <span><?php echo $err; ?></span>
    </div>
    <?php endif; ?>

    <form class="form" action="login_process.php" method="POST" autocomplete="on" id="loginForm" novalidate>
      <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

      <div class="form-group">
        <label for="role">
          <i class="fas fa-users"></i>
          เลือกสถานะผู้ใช้งาน
        </label>
        <div class="role-selection">
          <input type="radio" id="admin" name="role" value="admin" required>
          <label for="admin">
            <i class="fas fa-user-shield"></i>
            <span>ผู้ดูแลระบบ</span>
          </label>

          <input type="radio" id="manager" name="role" value="manager" required>
          <label for="manager">
            <i class="fas fa-user-tie"></i>
            <span>ผู้บริหาร</span>
          </label>

          <input type="radio" id="committee" name="role" value="committee" required>
          <label for="committee">
            <i class="fas fa-users-cog"></i>
            <span>กรรมการ</span>
          </label>

          <input type="radio" id="employee" name="role" value="employee" required>
          <label for="employee">
            <i class="fas fa-user-cog"></i>
            <span>พนักงาน</span>
          </label>

          <input type="radio" id="member" name="role" value="member" required>
          <label for="member">
            <i class="fas fa-users"></i>
            <span>สมาชิก</span>
          </label>
        </div>
      </div>

      <div class="form-group">
        <label for="username">
          <i class="fas fa-user"></i>
          ชื่อผู้ใช้หรืออีเมล
        </label>
        <div class="input">
          <i class="fa-regular fa-user input-icon" aria-hidden="true"></i>
          <input 
            id="username" 
            name="username" 
            type="text" 
            inputmode="email" 
            required 
            placeholder="เช่น user@gmail.com หรือชื่อผู้ใช้"
            autocomplete="username"
          />
        </div>
      </div>

      <div class="form-group">
        <label for="password">
          <i class="fas fa-lock"></i>
          รหัสผ่าน
        </label>
        <div class="input">
          <i class="fa-solid fa-lock input-icon" aria-hidden="true"></i>
          <input 
            id="password" 
            name="password" 
            type="password" 
            required 
            placeholder="กรอกรหัสผ่าน"
            autocomplete="current-password"
          />
          <button type="button" class="toggle-pass" aria-label="แสดง/ซ่อนรหัสผ่าน">
            <i class="fa-regular fa-eye"></i>
          </button>
        </div>
      </div>

      <div class="form-row">
        <a class="link-muted" href="forgot_password.php">
          <i class="fas fa-key"></i>
          ลืมรหัสผ่าน?
        </a>
      </div>

      <button type="submit" class="btn btn-primary">
        <i class="fa-solid fa-right-to-bracket"></i>
        <span>เข้าสู่ระบบ</span>
      </button>

      <div class="divider"></div>

      <a class="btn btn-outline" href="../index.php">
        <i class="fa-solid fa-house"></i>
        <span>กลับหน้าแรก</span>
      </a>
    </form>
  </main>

  <footer class="footer">
    <div class="copyright">© <?php echo date('Y'); ?> สหกรณ์ปั๊มน้ำบ้านภูเขาทอง | ระบบบริหารจัดการ</div>
  </footer>

  <script>
    // Toggle Password Visibility
    document.querySelector('.toggle-pass')?.addEventListener('click', function() {
      const passwordInput = document.getElementById('password');
      const icon = this.querySelector('i');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    });

    // Form Loading State
    document.getElementById('loginForm')?.addEventListener('submit', function(e) {
      const submitBtn = this.querySelector('button[type="submit"]');
      const selectedRole = this.querySelector('input[name="role"]:checked');
      
      if (!selectedRole) {
        e.preventDefault();
        alert('กรุณาเลือกสถานะผู้ใช้งาน');
        return;
      }
      
      submitBtn.classList.add('loading');
      submitBtn.disabled = true;
      
      // Reset after 5 seconds in case of network issues
      setTimeout(() => {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
      }, 5000);
    });

    // Role Selection Enhancement
    document.querySelectorAll('input[name="role"]').forEach(radio => {
      radio.addEventListener('change', function() {
        // Add selection animation
        if (this.checked) {
          const label = this.nextElementSibling;
          label.style.transform = 'translateY(-4px) scale(1.02)';
          setTimeout(() => {
            label.style.transform = 'translateY(-2px) scale(1)';
          }, 200);
        }
      });
    });

    // Input Enhancement
    document.querySelectorAll('.input input').forEach(input => {
      input.addEventListener('focus', function() {
        this.parentElement.style.transform = 'translateY(-2px)';
      });
      
      input.addEventListener('blur', function() {
        this.parentElement.style.transform = 'translateY(0)';
      });
    });

    // Keyboard navigation for role selection
    document.addEventListener('keydown', function(e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        const roles = document.querySelectorAll('input[name="role"]');
        const currentRole = document.querySelector('input[name="role"]:checked');
        
        if (currentRole) {
          const currentIndex = Array.from(roles).indexOf(currentRole);
          let nextIndex;
          
          if (e.key === 'ArrowRight') {
            nextIndex = (currentIndex + 1) % roles.length;
          } else {
            nextIndex = (currentIndex - 1 + roles.length) % roles.length;
          }
          
          roles[nextIndex].checked = true;
          roles[nextIndex].focus();
          
          // Trigger change event
          roles[nextIndex].dispatchEvent(new Event('change'));
        }
      }
    });

    // Add subtle hover effects to the form
    document.querySelectorAll('.btn, .role-selection label').forEach(element => {
      element.addEventListener('mouseenter', function() {
        this.style.transition = 'all 0.2s ease';
      });
    });

    // Auto-focus first input on page load
    window.addEventListener('load', function() {
      setTimeout(() => {
        const firstRole = document.getElementById('admin');
        if (firstRole) firstRole.focus();
      }, 500);
    });
  </script>
</body>
</html>