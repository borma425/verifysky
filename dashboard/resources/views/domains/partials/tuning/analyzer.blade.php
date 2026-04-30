<div class="vs-tuning-card vs-tuning-card-pad">
  <div class="vs-tuning-section-head">
    <h3 class="vs-tuning-kicker">
      <span class="material-symbols-outlined text-[1.15rem] text-[#10B981]">analytics</span>
      Live Domain Analyzer
    </h3>
    <button type="button" x-on:click="startAnalysis()" class="vs-tuning-button vs-tuning-button-compact" x-bind:disabled="isAnalyzing">
      <span x-text="isAnalyzing ? 'Analyzing...' : 'Analyze Domain'"></span>
    </button>
  </div>

  <div class="vs-tuning-grid vs-tuning-two-grid">
    <div>
      <label class="vs-tuning-mini-label">Pages Count</label>
      <div class="vs-tuning-input vs-tuning-input-display" x-text="pagesCount"></div>
    </div>

    <div class="transition-all" x-bind:class="{ 'ring-1 ring-[#FCB900]/40 rounded-lg': isEditingApi }">
      <label class="vs-tuning-mini-label">
        <span>API Count</span>
      </label>
      <input type="number" x-model.number="apiCount" x-on:focus="isEditingApi = true" x-on:blur="isEditingApi = false" min="0" class="vs-tuning-input" placeholder="0">
    </div>
  </div>

  <div x-show="isAnalyzing" style="display: none;" class="absolute inset-0 z-10 bg-[#090E18]/92 backdrop-blur-md flex flex-col items-center justify-center border-t border-[#34D399]/30" x-transition.opacity>
    <div x-show="!iframeLoaded" class="flex flex-col items-center justify-center animate-pulse px-4 text-center">
      <p class="vs-tuning-loading-title mb-2 text-xl">Analyzing domain...</p>
      <p class="vs-tuning-loading-copy">Wait while API calls are detected.</p>
    </div>
    <template x-if="isAnalyzing">
      <iframe x-bind:src="iframeUrl" x-on:load="iframeLoaded = true" class="w-full h-full max-h-[100%] rounded-lg border-2 border-[#34D399]/30 p-1 bg-white" x-bind:class="iframeLoaded ? 'opacity-100' : 'opacity-0 absolute'"></iframe>
    </template>
  </div>
</div>
