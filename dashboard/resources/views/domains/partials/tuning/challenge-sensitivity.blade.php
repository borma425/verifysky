<div class="border-t border-sky-500/20 pt-5 mt-6">
  <h4 class="mb-1 text-md font-semibold text-white/80">Challenge Sensitivity</h4>
  <p class="text-xs text-sky-300/60 mb-4">Edit values separately for Balanced and Aggressive modes.</p>

  <div class="flex rounded-xl overflow-hidden border border-gray-600/50 mb-5">
    <button type="button" x-on:click="setChallengePreset('balanced')" x-bind:class="challengeTabClass('balanced')" class="challenge-tab flex-1 px-4 py-3 text-sm font-semibold transition-all duration-200 outline-none cursor-pointer">Balanced</button>
    <button type="button" x-on:click="setChallengePreset('aggressive')" x-bind:class="challengeTabClass('aggressive')" class="challenge-tab flex-1 px-4 py-3 text-sm font-semibold transition-all duration-200 outline-none cursor-pointer">Aggressive</button>
  </div>

  <input type="hidden" name="challenge_min_solve_ms_balanced" x-ref="balancedSolve" value="{{ $challengeProfiles['balanced']['solve'] }}">
  <input type="hidden" name="challenge_min_telemetry_points_balanced" x-ref="balancedPoints" value="{{ $challengeProfiles['balanced']['points'] }}">
  <input type="hidden" name="challenge_x_tolerance_balanced" x-ref="balancedTolerance" value="{{ $challengeProfiles['balanced']['tolerance'] }}">
  <input type="hidden" name="challenge_min_solve_ms_aggressive" x-ref="aggressiveSolve" value="{{ $challengeProfiles['aggressive']['solve'] }}">
  <input type="hidden" name="challenge_min_telemetry_points_aggressive" x-ref="aggressivePoints" value="{{ $challengeProfiles['aggressive']['points'] }}">
  <input type="hidden" name="challenge_x_tolerance_aggressive" x-ref="aggressiveTolerance" value="{{ $challengeProfiles['aggressive']['tolerance'] }}">

  <div class="grid gap-4 md:grid-cols-3">
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">Min Solve Time</label>
      <input type="number" x-ref="challengeSolve" value="{{ $challengeProfiles['balanced']['solve'] }}" min="50" max="1000" class="es-input w-full" required x-on:input="detectChallengeMode()">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">Min Telemetry Points</label>
      <input type="number" x-ref="challengePoints" value="{{ $challengeProfiles['balanced']['points'] }}" min="2" max="20" class="es-input w-full" required x-on:input="detectChallengeMode()">
    </div>
    <div>
      <label class="mb-1 block text-sm font-medium text-sky-100">X Tolerance</label>
      <input type="number" x-ref="challengeTolerance" value="{{ $challengeProfiles['balanced']['tolerance'] }}" min="5" max="50" class="es-input w-full" required x-on:input="detectChallengeMode()">
    </div>
  </div>
</div>
