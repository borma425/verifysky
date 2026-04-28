<section>
  <h4 class="mb-1 text-md font-semibold text-white/90">Challenge Sensitivity</h4>
  <p class="vs-tuning-helper">Edit values separately for Balanced and Aggressive modes.</p>

  <div class="vs-tuning-tabs">
    <button type="button" x-on:click="setChallengePreset('balanced')" x-bind:class="challengeTabClass('balanced')" class="vs-tuning-tab challenge-tab outline-none cursor-pointer">Balanced</button>
    <button type="button" x-on:click="setChallengePreset('aggressive')" x-bind:class="challengeTabClass('aggressive')" class="vs-tuning-tab challenge-tab outline-none cursor-pointer">Aggressive</button>
  </div>

  <input type="hidden" name="challenge_min_solve_ms_balanced" x-ref="balancedSolve" value="{{ $challengeProfiles['balanced']['solve'] }}">
  <input type="hidden" name="challenge_min_telemetry_points_balanced" x-ref="balancedPoints" value="{{ $challengeProfiles['balanced']['points'] }}">
  <input type="hidden" name="challenge_x_tolerance_balanced" x-ref="balancedTolerance" value="{{ $challengeProfiles['balanced']['tolerance'] }}">
  <input type="hidden" name="challenge_min_solve_ms_aggressive" x-ref="aggressiveSolve" value="{{ $challengeProfiles['aggressive']['solve'] }}">
  <input type="hidden" name="challenge_min_telemetry_points_aggressive" x-ref="aggressivePoints" value="{{ $challengeProfiles['aggressive']['points'] }}">
  <input type="hidden" name="challenge_x_tolerance_aggressive" x-ref="aggressiveTolerance" value="{{ $challengeProfiles['aggressive']['tolerance'] }}">

  <div class="vs-tuning-grid vs-tuning-three-grid">
    <div>
      <label class="vs-tuning-label">Min Solve Time</label>
      <input type="number" x-ref="challengeSolve" value="{{ $challengeProfiles['balanced']['solve'] }}" min="50" max="1000" class="vs-tuning-input" required x-on:input="detectChallengeMode()">
    </div>
    <div>
      <label class="vs-tuning-label">Min Telemetry Points</label>
      <input type="number" x-ref="challengePoints" value="{{ $challengeProfiles['balanced']['points'] }}" min="2" max="20" class="vs-tuning-input" required x-on:input="detectChallengeMode()">
    </div>
    <div>
      <label class="vs-tuning-label">X Tolerance</label>
      <input type="number" x-ref="challengeTolerance" value="{{ $challengeProfiles['balanced']['tolerance'] }}" min="5" max="50" class="vs-tuning-input" required x-on:input="detectChallengeMode()">
    </div>
  </div>
</section>
