<?php
/**
 * auth/login.php
 * - LoginPage.tsx 역할카드(직원=blue / 임원=indigo) + 설명 + 1.5초 로딩 흉내
 */

if (\App\Core\Auth::check()) {
    header('Location: ?r=대시보드');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = isset($_POST['_csrf']) ? $_POST['_csrf'] : '';
    if (!csrf_check($token)) {
        flash_set('danger', '보안 토큰이 유효하지 않습니다. 새로고침 후 다시 시도해주세요.');
        header('Location: ?r=login');
        exit;
    }

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $role = isset($_POST['role']) ? (string)$_POST['role'] : 'employee';

    $ok = \App\Core\Auth::loginSample($email, $password, $role);
    if (!$ok) {
        flash_set('danger', '로그인 정보가 올바르지 않습니다. (비밀번호는 1234)');
        header('Location: ?r=login');
        exit;
    }

    header('Location: ?r=대시보드');
    exit;
}

$flash = flash_get();
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta http-equiv="x-ua-compatible" content="ie=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>로그인</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
</head>

<body class="min-h-screen">
  <div class="min-h-screen bg-gradient-to-br from-blue-600 via-blue-500 to-cyan-500 flex items-center justify-center p-4 overflow-hidden relative">
    <div class="absolute top-0 left-0 w-96 h-96 bg-white/10 rounded-full blur-3xl animate-pulse"></div>
    <div class="absolute bottom-0 right-0 w-96 h-96 bg-white/10 rounded-full blur-3xl animate-pulse" style="animation-delay:1s"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-64 h-64 bg-white/10 rounded-full blur-3xl animate-pulse" style="animation-delay:2s"></div>

    <div class="w-full max-w-6xl grid lg:grid-cols-2 gap-8 items-center relative z-10">
      <!-- Left -->
      <div class="hidden lg:flex flex-col gap-8 text-white">
        <div class="flex items-center gap-4">
          <div class="w-20 h-20 rounded-3xl bg-white/20 backdrop-blur-sm border border-white/20 flex items-center justify-center">
            <i data-lucide="building-2" class="w-10 h-10"></i>
          </div>
          <div>
            <h1 class="text-5xl font-bold mb-2">창명건설</h1>
            <p class="text-xl text-blue-100">Construction Management System</p>
          </div>
        </div>

        <div class="space-y-6 mt-8">
          <div class="flex items-start gap-4 bg-white/10 backdrop-blur-sm p-6 rounded-3xl border border-white/20">
            <div class="p-3 bg-white/20 rounded-2xl"><i data-lucide="shield" class="w-6 h-6"></i></div>
            <div>
              <h3 class="text-xl font-bold mb-2">통합 관리</h3>
              <p class="text-blue-100">프로젝트부터 안전, 품질까지 한 번에</p>
            </div>
          </div>
          <div class="flex items-start gap-4 bg-white/10 backdrop-blur-sm p-6 rounded-3xl border border-white/20">
            <div class="p-3 bg-white/20 rounded-2xl"><i data-lucide="check-circle-2" class="w-6 h-6"></i></div>
            <div>
              <h3 class="text-xl font-bold mb-2">실시간 현황</h3>
              <p class="text-blue-100">업무 진행상황을 즉시 확인</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Right -->
      <div class="w-full max-w-md mx-auto">
        <div class="bg-white/95 backdrop-blur-lg rounded-3xl shadow-2xl p-8 border border-white/20">
          <div class="text-center mb-8">
            <h2 class="text-3xl font-bold text-gray-800 mb-2">로그인</h2>
            <p class="text-gray-600">계정으로 시스템에 접속하세요</p>
          </div>

          <?php if (!empty($flash) && is_array($flash)): ?>
            <div class="mb-4 rounded-2xl border px-4 py-3 bg-red-50 border-red-200 text-red-700">
              <?php echo h($flash['message']); ?>
            </div>
          <?php endif; ?>

          <form method="post" action="?r=login" class="space-y-5" id="loginForm">
            <input type="hidden" name="_csrf" value="<?php echo h(csrf_token()); ?>">
            <input type="hidden" name="role" id="roleInput" value="employee">

            <!-- Role Selection (원본과 동일: 직원 blue / 임원 indigo) -->
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-3">로그인 유형</label>
              <div class="grid grid-cols-2 gap-3">
                <button type="button" id="btnEmployee"
                  class="flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-blue-500 bg-blue-50 shadow-md shadow-blue-500/20">
                  <div class="p-2 rounded-xl bg-blue-500">
                    <i data-lucide="user" class="w-5 h-5 text-white"></i>
                  </div>
                  <div class="text-left">
                    <div class="font-bold text-blue-600">직원</div>
                    <div class="text-xs text-gray-500">일반 사용자</div>
                  </div>
                </button>

                <button type="button" id="btnExecutive"
                  class="flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-gray-200 bg-gray-50 hover:border-indigo-300">
                  <div class="p-2 rounded-xl bg-gray-300">
                    <i data-lucide="user-cog" class="w-5 h-5 text-white"></i>
                  </div>
                  <div class="text-left">
                    <div class="font-bold text-gray-700">임원</div>
                    <div class="text-xs text-gray-500">관리자</div>
                  </div>
                </button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">이메일</label>
              <div class="relative">
                <i data-lucide="mail" class="w-5 h-5 text-gray-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                <input
                  class="w-full pl-12 pr-4 py-3 rounded-2xl border border-gray-200 focus:ring-4 focus:ring-blue-200 focus:border-blue-400 outline-none"
                  type="email" name="email" placeholder="kim@cm.kr" autocomplete="username" required>
              </div>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">비밀번호</label>
              <div class="relative">
                <i data-lucide="lock" class="w-5 h-5 text-gray-400 absolute left-4 top-1/2 -translate-y-1/2"></i>
                <input
                  class="w-full pl-12 pr-4 py-3 rounded-2xl border border-gray-200 focus:ring-4 focus:ring-blue-200 focus:border-blue-400 outline-none"
                  type="password" name="password" placeholder="1234" autocomplete="current-password" required>
              </div>
            </div>

            <button id="submitBtn"
              class="w-full py-3 rounded-2xl bg-gradient-to-r from-blue-600 to-cyan-500 text-white font-bold shadow-lg hover:shadow-xl transition flex items-center justify-center gap-2"
              type="submit">
              <span id="submitText">로그인</span>
              <i id="submitIcon" data-lucide="arrow-right" class="w-5 h-5"></i>
            </button>

            <div class="text-xs text-gray-500 leading-6 bg-gray-50 rounded-2xl p-4 border border-gray-100">
              <div class="font-bold text-gray-700 mb-1">샘플 계정</div>
              <div>비밀번호는 <b>1234</b>로 로그인 가능</div>
              <div class="mt-1">이메일: kim@cm.kr, lee@cm.kr, park@cm.kr 등</div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="absolute bottom-0 left-0 right-0 h-1 bg-gradient-to-r from-blue-600 via-blue-500 to-cyan-500"></div>
  </div>

  <script>
    if (window.lucide) lucide.createIcons();

    // 역할 선택 UI (원본 로직 그대로: employee=blue / executive=indigo)
    var role = 'employee';
    var roleInput = document.getElementById('roleInput');

    var btnEmp = document.getElementById('btnEmployee');
    var btnExec = document.getElementById('btnExecutive');

    function setRole(next) {
      role = next;
      roleInput.value = next;

      if (next === 'employee') {
        btnEmp.className  = 'flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-blue-500 bg-blue-50 shadow-md shadow-blue-500/20';
        btnEmp.querySelector('div.p-2').className = 'p-2 rounded-xl bg-blue-500';
        btnEmp.querySelector('div.font-bold').className = 'font-bold text-blue-600';

        btnExec.className = 'flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-gray-200 bg-gray-50 hover:border-indigo-300';
        btnExec.querySelector('div.p-2').className = 'p-2 rounded-xl bg-gray-300';
        btnExec.querySelector('div.font-bold').className = 'font-bold text-gray-700';
      } else {
        btnExec.className = 'flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-indigo-500 bg-indigo-50 shadow-md shadow-indigo-500/20';
        btnExec.querySelector('div.p-2').className = 'p-2 rounded-xl bg-indigo-500';
        btnExec.querySelector('div.font-bold').className = 'font-bold text-indigo-600';

        btnEmp.className  = 'flex items-center gap-3 p-4 rounded-2xl border-2 transition-all duration-300 border-gray-200 bg-gray-50 hover:border-blue-300';
        btnEmp.querySelector('div.p-2').className = 'p-2 rounded-xl bg-gray-300';
        btnEmp.querySelector('div.font-bold').className = 'font-bold text-gray-700';
      }
      if (window.lucide) lucide.createIcons();
    }

    btnEmp.onclick = function(){ setRole('employee'); };
    btnExec.onclick = function(){ setRole('executive'); };

    // 로그인 로딩(원본: 1.5초 시뮬레이션)
    var form = document.getElementById('loginForm');
    var submitBtn = document.getElementById('submitBtn');
    var submitText = document.getElementById('submitText');
    var submitIcon = document.getElementById('submitIcon');

    form.addEventListener('submit', function(e){
      e.preventDefault();

      submitBtn.disabled = true;
      submitBtn.className = submitBtn.className + ' opacity-90';
      submitText.textContent = '로그인 중...';
      submitIcon.setAttribute('data-lucide','loader-circle');
      submitIcon.className = 'w-5 h-5 animate-spin';
      if (window.lucide) lucide.createIcons();

      setTimeout(function(){
        form.submit();
      }, 1500);
    });
  </script>
</body>
</html>