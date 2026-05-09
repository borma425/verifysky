@extends($layout ?? 'layouts.app')

@section('content')
  @php
    $userInitial = strtoupper(substr((string) $user->name, 0, 1));
  @endphp

  <div
    class="mx-auto max-w-6xl space-y-4"
    data-initial-avatar-url="{{ $avatarUrl }}"
    x-data="{
      initialAvatarUrl: null,
      avatarPreview: null,
      avatarFileName: '',
      removeAvatar: false,
      init() {
        this.initialAvatarUrl = this.$el.dataset.initialAvatarUrl || null;
      },
      previewAvatar(event) {
        const file = event.target.files && event.target.files[0] ? event.target.files[0] : null;
        if (!file) {
          this.avatarPreview = null;
          this.avatarFileName = '';
          return;
        }

        this.removeAvatar = false;
        this.avatarFileName = file.name;

        const reader = new FileReader();
        reader.onload = (loadEvent) => {
          this.avatarPreview = loadEvent.target.result;
        };
        reader.readAsDataURL(file);
      },
      removeCurrentAvatar() {
        if (!this.removeAvatar) {
          return;
        }

        this.avatarPreview = null;
        this.avatarFileName = '';
        if (this.$refs.avatarInput) {
          this.$refs.avatarInput.value = '';
        }
      }
    }"
  >
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
      <div>
        <h2 class="text-2xl font-black leading-tight text-[#D7E1F5]">Account Settings</h2>
        <p class="mt-1 text-sm text-[#AEB9CC]">Manage your profile and account login details.</p>
      </div>

      <div class="inline-flex w-full items-center gap-3 rounded-lg border border-[#303540] bg-[#1B202A] px-4 py-3 md:w-auto">
        <div class="relative flex h-14 w-14 shrink-0 items-center justify-center overflow-hidden rounded-full border border-[#303540] bg-[#252A34] text-base font-black text-[#FCB900]">
          <img
            x-show="avatarPreview || (!removeAvatar && initialAvatarUrl)"
            x-bind:src="avatarPreview || initialAvatarUrl"
            alt=""
            class="h-full w-full object-cover"
          >
          <span x-show="!avatarPreview && (removeAvatar || !initialAvatarUrl)">{{ $userInitial }}</span>
        </div>
        <div class="min-w-0">
          <div class="truncate text-sm font-bold text-[#FFFFFF]">{{ $user->email }}</div>
          <div class="mt-0.5 truncate text-xs font-semibold uppercase tracking-[0.12em] text-[#FCB900]">{{ $tenant->name }}</div>
        </div>
      </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-[#303540] bg-[#1B202A]">
      <form method="POST" action="{{ route($settingsUpdateRoute ?? 'settings.update') }}" enctype="multipart/form-data" class="divide-y divide-[#303540]/70">
        @csrf

        <section class="p-5 md:p-6">
          <div class="mb-4 flex items-center justify-between gap-3 border-b border-[#303540]/70 pb-3">
            <h3 class="text-base font-bold text-[#D7E1F5]">User Profile</h3>
          </div>

          <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="grid content-start gap-4">
              <div>
                <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Email</label>
                <input class="es-input border-[#303540] bg-[#252A34]" type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email">
                @error('email')
                  <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
                @enderror
              </div>

              <div>
                <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Avatar</label>
                <label class="group relative flex min-h-28 cursor-pointer flex-col items-center justify-center rounded-lg border border-dashed border-[#303540] bg-[#252A34] px-4 py-5 text-center transition-colors hover:border-[#FCB900]/70">
                  <input
                    x-ref="avatarInput"
                    x-on:change="previewAvatar($event)"
                    class="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                    type="file"
                    name="avatar"
                    accept="image/*"
                  >
                  <span class="inline-flex h-9 w-9 items-center justify-center rounded-md border border-[#303540] bg-[#1B202A] text-[#FCB900] transition-colors group-hover:border-[#FCB900]/50">
                    <img src="{{ asset('duotone/plus.svg') }}" alt="" class="es-duotone-icon es-icon-tone-brass h-4 w-4">
                  </span>
                  <span class="mt-3 text-sm font-bold text-[#D7E1F5]">Choose image</span>
                  <span class="mt-1 max-w-full truncate text-xs text-[#AEB9CC]" x-text="avatarFileName || 'PNG, JPG, or WEBP up to 2MB'"></span>
                </label>
                @error('avatar')
                  <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
                @enderror

                @if($avatarUrl)
                  <label class="mt-3 flex w-fit items-center gap-2 rounded-md border border-[#303540] bg-[#252A34] px-3 py-2 text-sm font-semibold text-[#D7E1F5]">
                    <input
                      x-model="removeAvatar"
                      x-on:change="removeCurrentAvatar()"
                      type="checkbox"
                      name="remove_avatar"
                      value="1"
                      class="h-4 w-4 rounded border-[#303540] bg-[#0E131D] text-[#FCB900] focus:ring-[#FCB900] focus:ring-offset-[#1B202A]"
                    >
                    Remove current avatar
                  </label>
                @endif
              </div>
            </div>

            <div class="rounded-lg border border-[#303540] bg-[#252A34] p-4">
              <div class="text-xs font-bold uppercase tracking-[0.12em] text-[#AEB9CC]">Avatar preview</div>
              <div class="mt-4 flex flex-col items-center text-center">
                <div class="flex h-24 w-24 items-center justify-center overflow-hidden rounded-full border border-[#303540] bg-[#1B202A] text-3xl font-black text-[#FCB900]">
                  <img
                    x-show="avatarPreview || (!removeAvatar && initialAvatarUrl)"
                    x-bind:src="avatarPreview || initialAvatarUrl"
                    alt=""
                    class="h-full w-full object-cover"
                  >
                  <span x-show="!avatarPreview && (removeAvatar || !initialAvatarUrl)">{{ $userInitial }}</span>
                </div>
                <div class="mt-3 max-w-full truncate text-sm font-bold text-[#FFFFFF]">{{ $user->name }}</div>
                <div class="mt-1 max-w-full truncate text-xs text-[#AEB9CC]">{{ $user->email }}</div>
              </div>
            </div>
          </div>
        </section>

        <section class="p-5 md:p-6">
          <div class="mb-4 flex items-center justify-between gap-3 border-b border-[#303540]/70 pb-3">
            <h3 class="text-base font-bold text-[#D7E1F5]">Security</h3>
          </div>

          <div class="grid gap-4 md:grid-cols-3">
            <div>
              <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Current Password</label>
              <input class="es-input border-[#303540] bg-[#252A34]" type="password" name="current_password" autocomplete="current-password">
              @error('current_password')
                <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
              @enderror
            </div>
            <div>
              <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">New Password</label>
              <input class="es-input border-[#303540] bg-[#252A34]" type="password" name="password" autocomplete="new-password">
              @error('password')
                <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
              @enderror
            </div>
            <div>
              <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Confirm Password</label>
              <input class="es-input border-[#303540] bg-[#252A34]" type="password" name="password_confirmation" autocomplete="new-password">
            </div>
          </div>
        </section>

        @if($canManageWorkspace)
          <section class="p-5 md:p-6">
            <div class="mb-4 flex items-center justify-between gap-3 border-b border-[#303540]/70 pb-3">
              <h3 class="text-base font-bold text-[#D7E1F5]">Account Access</h3>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
              <div>
                <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Account Name</label>
                <input class="es-input border-[#303540] bg-[#252A34]" name="name" value="{{ old('name', $tenant->name) }}" required>
                @error('name')
                  <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
                @enderror
              </div>
              <div>
                <label class="mb-1.5 block text-sm font-semibold text-[#D7E1F5]">Login Slug</label>
                <div class="flex flex-col overflow-hidden rounded-md border border-[#303540] bg-[#252A34] transition-colors focus-within:border-[#FCB900] sm:flex-row">
                  <span class="inline-flex min-h-10 max-w-full items-center overflow-hidden text-ellipsis whitespace-nowrap border-b border-[#303540] bg-[#1B202A] px-3 text-xs font-semibold text-[#AEB9CC] sm:max-w-[56%] sm:border-b-0 sm:border-r sm:text-sm">{{ $loginUrlPrefix }}</span>
                  <input class="min-w-0 flex-1 border-0 bg-transparent px-3 py-2 text-sm text-[#D7E1F5] outline-none placeholder:text-[#AEB9CC] focus:ring-0" name="login_slug" value="{{ old('login_slug', $currentLoginSlug) }}" required>
                </div>
                @error('login_slug')
                  <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
                @enderror
                <p class="mt-2 overflow-x-auto rounded-md border border-[#303540] bg-[#0E131D] px-3 py-2 text-xs text-[#AEB9CC]"><code>{{ $currentLoginUrl }}</code></p>
              </div>
            </div>
          </section>

          <section class="p-5 md:p-6">
            <div class="mb-4 flex flex-col gap-3 border-b border-[#303540]/70 pb-3 md:flex-row md:items-center md:justify-between">
              <div>
                <h3 class="text-base font-bold text-[#D7E1F5]">Team</h3>
                <p class="mt-1 text-sm text-[#AEB9CC]">Invite workspace users and manage access.</p>
              </div>
              <span class="inline-flex w-fit rounded-full border border-[#303540] bg-[#252A34] px-3 py-1 text-xs font-bold text-[#AEB9CC]">{{ $tenant->memberships->count() }} workspace user(s)</span>
            </div>

            <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
              <div class="overflow-hidden rounded-lg border border-[#303540] bg-[#252A34]">
                <div class="grid grid-cols-[minmax(0,1fr)_7rem_6rem] gap-3 border-b border-[#303540] bg-[#0E131D] px-4 py-2 text-[11px] font-black uppercase tracking-[0.12em] text-[#7F8BA0]">
                  <span>User</span>
                  <span>Role</span>
                  <span class="text-right">Action</span>
                </div>
                <div class="divide-y divide-[#303540]/70">
                  @foreach($tenant->memberships as $membership)
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_6rem] items-center gap-3 px-4 py-3">
                      <div class="min-w-0">
                        <div class="truncate text-sm font-bold text-[#FFFFFF]">{{ $membership->user?->name ?? 'Unknown user' }}</div>
                        <div class="truncate text-xs text-[#AEB9CC]">{{ $membership->user?->email }}</div>
                      </div>
                      <span class="w-fit rounded-md border border-[#303540] bg-[#1B202A] px-2 py-1 text-xs font-bold text-[#D7E1F5]">{{ $membership->role }}</span>
                      <form method="POST" action="{{ route('settings.team.members.destroy', $membership) }}" onsubmit="return confirm('Remove this user from the workspace?');" class="text-right">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs font-bold text-[#FFE6E3] hover:text-[#FFFFFF]" type="submit">Remove</button>
                      </form>
                    </div>
                  @endforeach
                </div>
              </div>

              <div class="grid content-start gap-4">
                <form method="POST" action="{{ route('settings.team.invitations.store') }}" class="rounded-lg border border-[#303540] bg-[#252A34] p-4">
                  @csrf
                  <h4 class="text-sm font-bold text-[#FFFFFF]">Invite teammate</h4>
                  <div class="mt-3 grid gap-3">
                    <div>
                      <label class="mb-1.5 block text-xs font-bold text-[#AEB9CC]">Email</label>
                      <input class="es-input border-[#303540] bg-[#1B202A]" type="email" name="email" value="{{ old('email') }}" required>
                      @error('email')
                        <p class="mt-1.5 text-xs font-semibold text-[#D47B78]">{{ $message }}</p>
                      @enderror
                    </div>
                    <div>
                      <label class="mb-1.5 block text-xs font-bold text-[#AEB9CC]">Role</label>
                      <select name="role" class="es-input border-[#303540] bg-[#1B202A]">
                        <option value="member">Member</option>
                        <option value="owner">Owner</option>
                      </select>
                    </div>
                    <button class="es-btn min-h-10 w-full" type="submit">Send Invitation</button>
                  </div>
                </form>

                <div class="rounded-lg border border-[#303540] bg-[#252A34] p-4">
                  <h4 class="text-sm font-bold text-[#FFFFFF]">Pending invitations</h4>
                  <div class="mt-3 grid gap-2">
                    @forelse($tenant->invitations as $invitation)
                      <div class="flex items-center justify-between gap-3 rounded-md border border-[#303540] bg-[#1B202A] px-3 py-2">
                        <div class="min-w-0">
                          <div class="truncate text-xs font-bold text-[#D7E1F5]">{{ $invitation->email }}</div>
                          <div class="mt-0.5 text-[11px] text-[#AEB9CC]">{{ $invitation->role }} · expires {{ $invitation->expires_at->diffForHumans() }}</div>
                        </div>
                        <form method="POST" action="{{ route('settings.team.invitations.destroy', $invitation) }}">
                          @csrf
                          @method('DELETE')
                          <button class="text-xs font-bold text-[#FFE6E3] hover:text-[#FFFFFF]" type="submit">Cancel</button>
                        </form>
                      </div>
                    @empty
                      <div class="rounded-md border border-[#303540] bg-[#1B202A] px-3 py-6 text-center text-sm text-[#AEB9CC]">No pending invitations.</div>
                    @endforelse
                  </div>
                </div>
              </div>
            </div>
          </section>
        @endif

        <section class="p-5 md:p-6">
          <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <div>
              <div class="mb-4 flex items-center justify-between gap-3 border-b border-[#303540]/70 pb-3">
                <h3 class="text-base font-bold text-[#D7E1F5]">User Name</h3>
              </div>
              <div class="rounded-lg border border-[#303540] bg-[#252A34] px-4 py-3">
                <div class="text-sm font-bold text-[#FFFFFF]">{{ $user->name }}</div>
                <div class="mt-1 text-xs text-[#AEB9CC]">Displayed from your account record and cannot be changed here.</div>
              </div>
            </div>

            <div class="flex justify-start lg:justify-end">
              <button class="es-btn min-h-10 w-full px-5 sm:w-auto" type="submit">Save Account Settings</button>
            </div>
          </div>
        </section>
      </form>
    </div>
  </div>
@endsection
