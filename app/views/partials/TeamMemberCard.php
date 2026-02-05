<?php
/**
 * partials/TeamMemberCard.php
 * - 팀원 카드
 * - 사용: render_team_member($name, $roleText)
 */
function render_team_member($name, $roleText) {
    $initial = function_exists('mb_substr') ? mb_substr($name, 0, 1, 'UTF-8') : substr($name, 0, 1);
    ?>
    <div class="flex items-center gap-3 p-4 rounded-2xl border border-gray-100 bg-white hover:shadow-md transition">
        <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-full flex items-center justify-center shadow-md">
            <span class="text-white font-bold text-sm"><?php echo h($initial); ?></span>
        </div>
        <div class="flex-1">
            <div class="font-bold text-gray-900"><?php echo h($name); ?></div>
            <div class="text-xs text-gray-500"><?php echo h($roleText); ?></div>
        </div>
        <div class="w-2 h-2 bg-green-500 rounded-full shadow-sm shadow-green-500/50"></div>
    </div>
    <?php
}