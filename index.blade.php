@extends('layouts.app')

@section('title', '職員設定')

@section('content')
    <h1>職員設定</h1>

    @if(session('success'))
        <div class="box">{{ session('success') }}</div>
    @endif

    @if(session('error'))
        <div class="box">{{ session('error') }}</div>
    @endif

    @if($errors->any())
        <div class="box">
            <p><strong>入力に問題があります：</strong></p>
            <ul>
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if(!$corporate)
        <div class="box">
            <p>法人がまだありません。</p>
            <p class="muted">先に corporates を1件作成してください。</p>
        </div>
    @else
        <div class="box">
            <p>対象法人：{{ $corporate->name }}（id: {{ $corporate->id }}）</p>
        </div>

        @php
            // 資格の選択肢はコントローラから渡された「唯一の正」(EmploeeQualification::VOCABULARY)を
            // そのまま使う。値＝表示テキストが日本語で一致しているので、旧版のような英語⇔日本語の
            // 変換(normalize)は不要になった。資格を追加/変更したいときはモデルの VOCABULARY を直す。
            $qualificationVocabulary = $qualificationVocabulary ?? [];
            // 適用月の既定値（今月）。<input type="month"> は YYYY-MM 形式を受け取る。
            $defaultQualificationYm = now()->format('Y-m');
        @endphp

        <div class="box">
            <form method="GET" action="{{ route('setting.emploees.index') }}">
                <div class="row">
                    <div>
                        <label>施設</label>
                        <select name="facility_id">
                            <option value="0" @selected($facilityId === 0)>全施設</option>
                            @foreach($facilities as $facility)
                                <option value="{{ $facility->id }}" @selected($facilityId === (int) $facility->id)>
                                    {{ $facility->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label>対象日</label>
                        <input type="date" name="target_date" value="{{ $targetDate }}">
                    </div>

                    <div style="align-self: end;">
                        <button type="submit">表示</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="box">
            <h2>職員マスタ</h2>

            @if($emploeeMasterRows->isEmpty())
                <p class="muted">まだ職員がありません。下のフォームから追加してください。</p>
            @else
                <table border="1" cellpadding="8" cellspacing="0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>名前</th>
                        <th>外部職員コード</th>
                        <th>資格</th>
                        <th>在籍開始日</th>
                        <th>在籍終了日</th>
                        <th>在籍フラグ</th>
                        <th>施設</th>
                        <th>正規／非正規</th>
                        <th>勤務タイプ</th>
                        <th>常勤換算</th>
                        <th>配属開始日</th>
                        <th>配属終了日</th>
                        <th>休職開始日</th>
                        <th>休職終了日</th>
                        <th>操作</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($emploeeMasterRows as $masterRow)
                        @php
                            $emploee = $masterRow['emploee'];
                            $updateFormId = 'emploee-update-'.$emploee->id;
                            $employmentTypeValue = $masterRow['assignment_employment_type'] ?? '';
                            $selectedWorkStyleId = (int) ($masterRow['assignment_work_style_id'] ?? 0);
                            $updateFteTargetId = 'assignment-fte-display-'.$emploee->id;
                        @endphp
                        <tr>
                            <td>{{ $emploee->id }}</td>
                            <td>
                                <input type="text" name="name" value="{{ $emploee->name }}" form="{{ $updateFormId }}" required>
                            </td>
                            <td>
                                <input type="text" name="external_staff_code" value="{{ $emploee->external_staff_code ?? '' }}" form="{{ $updateFormId }}" placeholder="例: S0001">
                            </td>
                            <td>
                                <select name="qualification" form="{{ $updateFormId }}" required>
                                    {{-- 値＝表示テキストが一致するので、現在値と一致する選択肢を selected にするだけ。 --}}
                                    @foreach($qualificationVocabulary as $qualificationValue)
                                        <option value="{{ $qualificationValue }}" @selected(trim((string) $emploee->qualification) === $qualificationValue)>
                                            {{ $qualificationValue }}
                                        </option>
                                    @endforeach
                                </select>
                                {{-- 適用月：この資格を「いつから」にするか。既定は今月。月を変えて更新すると履歴が積まれる。 --}}
                                <label class="muted" style="display:block; margin-top:4px;">適用月</label>
                                <input type="month" name="qualification_effective_ym" value="{{ $defaultQualificationYm }}" form="{{ $updateFormId }}" required>
                                {{-- 資格履歴の簡易表示（新しい適用月が上）。1件も無ければ何も出さない。 --}}
                                @if(!empty($masterRow['qualification_history']))
                                    <details style="margin-top:4px;">
                                        <summary class="muted">資格履歴（{{ count($masterRow['qualification_history']) }}件）</summary>
                                        <ul style="margin:4px 0 0; padding-left:1.2em;">
                                            @foreach($masterRow['qualification_history'] as $history)
                                                <li class="muted">{{ $history['effective_from_ym'] }}〜：{{ $history['qualification'] }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </td>
                            <td>
                                <input type="date" name="employment_start_date" value="{{ $emploee->employment_start_date?->format('Y-m-d') }}" form="{{ $updateFormId }}" required>
                            </td>
                            <td>
                                <input type="date" name="employment_end_date" value="{{ $emploee->employment_end_date?->format('Y-m-d') }}" form="{{ $updateFormId }}">
                            </td>
                            <td>{{ $masterRow['retired_flag'] }}</td>
                            <td>
                                <select name="assignment_facility_id" form="{{ $updateFormId }}" required>
                                    <option value="">選択してください</option>
                                    @foreach($facilities as $facility)
                                        <option value="{{ $facility->id }}" @selected((string) ($masterRow['assignment_facility_id'] ?? '') === (string) $facility->id)>
                                            {{ $facility->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <select name="assignment_employment_type" form="{{ $updateFormId }}" required>
                                    <option value="">選択してください</option>
                                    <option value="正規" @selected($employmentTypeValue === '正規')>正規</option>
                                    <option value="非正規" @selected($employmentTypeValue === '非正規')>非正規</option>
                                </select>
                            </td>
                            <td>
                                <select
                                    name="assignment_work_style_id"
                                    form="{{ $updateFormId }}"
                                    class="js-work-style-select"
                                    data-fte-target="{{ $updateFteTargetId }}"
                                    required
                                >
                                    <option value="">選択してください</option>
                                    @foreach($workStyles as $workStyle)
                                        <option
                                            value="{{ $workStyle->id }}"
                                            data-fte="{{ number_format((float) $workStyle->fte, 2, '.', '') }}"
                                            @selected($selectedWorkStyleId === (int) $workStyle->id)
                                        >
                                            {{ $workStyle->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </td>
                            <td>
                                <span id="{{ $updateFteTargetId }}">{{ $masterRow['assignment_fte'] ?? '-' }}</span>
                            </td>
                            <td>
                                <input type="date" name="assignment_start_date" value="{{ $masterRow['assignment_start_date_raw'] ?? '' }}" form="{{ $updateFormId }}" required>
                            </td>
                            <td>
                                <input type="date" name="assignment_end_date" value="{{ $masterRow['assignment_end_date_raw'] ?? '' }}" form="{{ $updateFormId }}">
                            </td>
                            <td>
                                {{-- TODO: 休職は将来的に複数期間入力へ拡張する。 --}}
                                <input type="date" name="assignment_leave_start_date" value="{{ $masterRow['assignment_leave_start_date_raw'] ?? '' }}" form="{{ $updateFormId }}">
                            </td>
                            <td>
                                <input type="date" name="assignment_leave_end_date" value="{{ $masterRow['assignment_leave_end_date_raw'] ?? '' }}" form="{{ $updateFormId }}">
                            </td>
                            <td>
                                <form id="{{ $updateFormId }}" method="POST" action="{{ route('setting.emploees.update', $emploee) }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="facility_id" value="{{ $facilityId }}">
                                    <input type="hidden" name="target_date" value="{{ $targetDate }}">
                                    <input type="hidden" name="assignment_id" value="{{ $masterRow['assignment_id'] ?? '' }}">
                                    <button type="submit">更新</button>
                                </form>
                                @if(($masterRow['assignment_count'] ?? 0) > 1)
                                    <div class="muted">複数配属あり。先頭1件を基準に履歴を追加します。</div>
                                @endif
                                <form method="POST" action="{{ route('setting.emploees.destroy', $emploee) }}" onsubmit="return confirm('この職員を削除しますか？');">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="facility_id" value="{{ $facilityId }}">
                                    <input type="hidden" name="target_date" value="{{ $targetDate }}">
                                    <button type="submit">削除</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="box">
            <h2>職員を追加</h2>

            @php
                $createSelectedWorkStyleId = (int) old('assignment_work_style_id', 0);
                $createFteTargetId = 'assignment-fte-display-create';
            @endphp

            <form method="POST" action="{{ route('setting.emploees.store') }}">
                @csrf
                <input type="hidden" name="facility_id" value="{{ $facilityId }}">
                <input type="hidden" name="target_date" value="{{ $targetDate }}">

                <div class="row">
                    <div>
                        <label>名前（必須）</label>
                        <input type="text" name="name" value="{{ old('name') }}" required>
                    </div>
                    <div>
                        <label>外部職員コード（任意）</label>
                        <input type="text" name="external_staff_code" value="{{ old('external_staff_code') }}" placeholder="例: S0001">
                    </div>
                    <div>
                        <label>資格（必須）</label>
                        <select name="qualification" required>
                            @php
                                // 入力エラーで戻ってきたら old() の値、初回は先頭(保育士)を選択。
                                $createQualification = old('qualification', $qualificationVocabulary[0] ?? '');
                            @endphp
                            @foreach($qualificationVocabulary as $qualificationValue)
                                <option value="{{ $qualificationValue }}" @selected($createQualification === $qualificationValue)>
                                    {{ $qualificationValue }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>資格の適用月（必須）</label>
                        {{-- この資格を「いつから」にするか。既定は今月。資格履歴の起点になる。 --}}
                        <input type="month" name="qualification_effective_ym" value="{{ old('qualification_effective_ym', $defaultQualificationYm) }}" required>
                    </div>
                    <div>
                        <label>在籍開始日（必須）</label>
                        <input type="date" name="employment_start_date" value="{{ old('employment_start_date') }}" required>
                    </div>
                    <div>
                        <label>在籍終了日（任意）</label>
                        <input type="date" name="employment_end_date" value="{{ old('employment_end_date') }}">
                    </div>
                    <div style="align-self: end;">
                        <button type="submit">追加</button>
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div>
                        <label>配属施設（必須）</label>
                        <select name="assignment_facility_id" required>
                            <option value="">選択してください</option>
                            @foreach($facilities as $facility)
                                <option value="{{ $facility->id }}" @selected((string) old('assignment_facility_id', $facilityId > 0 ? $facilityId : '') === (string) $facility->id)>
                                    {{ $facility->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label>正規／非正規（必須）</label>
                        <select name="assignment_employment_type" required>
                            <option value="">選択してください</option>
                            <option value="正規" @selected(old('assignment_employment_type') === '正規')>正規</option>
                            <option value="非正規" @selected(old('assignment_employment_type') === '非正規')>非正規</option>
                        </select>
                    </div>
                    <div>
                        <label>勤務タイプ（必須）</label>
                        <select
                            name="assignment_work_style_id"
                            class="js-work-style-select"
                            data-fte-target="{{ $createFteTargetId }}"
                            required
                        >
                            <option value="">選択してください</option>
                            @foreach($workStyles as $workStyle)
                                <option
                                    value="{{ $workStyle->id }}"
                                    data-fte="{{ number_format((float) $workStyle->fte, 2, '.', '') }}"
                                    @selected($createSelectedWorkStyleId === (int) $workStyle->id)
                                >
                                    {{ $workStyle->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div>
                        <label>常勤換算（勤務タイプから自動表示）</label>
                        <span id="{{ $createFteTargetId }}">-</span>
                    </div>
                    <div>
                        <label>配属開始日（必須）</label>
                        <input type="date" name="assignment_start_date" value="{{ old('assignment_start_date', $targetDate) }}" required>
                    </div>
                    <div>
                        <label>配属終了日（任意）</label>
                        <input type="date" name="assignment_end_date" value="{{ old('assignment_end_date') }}">
                    </div>
                </div>

                <div class="row" style="margin-top: 12px;">
                    <div>
                        {{-- TODO: 休職は将来的に複数期間入力へ拡張する。 --}}
                        <label>休職開始日（任意）</label>
                        <input type="date" name="assignment_leave_start_date" value="{{ old('assignment_leave_start_date') }}">
                    </div>
                    <div>
                        <label>休職終了日（任意）</label>
                        <input type="date" name="assignment_leave_end_date" value="{{ old('assignment_leave_end_date') }}">
                    </div>
                </div>
            </form>
        </div>

        <div class="box">
            <h2>配属一覧</h2>

            @if($rows->isEmpty())
                <p class="muted">該当する職員データがありません。</p>
            @else
                <table border="1" cellpadding="8" cellspacing="0">
                    <thead>
                    <tr>
                        <th>施設</th>
                        <th>名前</th>
                        <th>資格</th>
                        <th>正規／非正規</th>
                        <th>開始日</th>
                        <th>終了日</th>
                        <th>在籍フラグ</th>
                        <th>勤務タイプ</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $row)
                        <tr>
                            <td>{{ $row['facility'] }}</td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['qualification'] }}</td>
                            <td>{{ $row['employment_type'] }}</td>
                            <td>{{ $row['start_date'] }}</td>
                            <td>{{ $row['end_date'] }}</td>
                            <td>{{ $row['retired_flag'] }}</td>
                            <td>{{ $row['work_style'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    @endif

    <script>
        (() => {
            const selects = document.querySelectorAll('.js-work-style-select');
            if (selects.length === 0) {
                return;
            }

            const syncFteDisplay = (selectElement) => {
                const targetId = selectElement.getAttribute('data-fte-target');
                if (!targetId) {
                    return;
                }

                const target = document.getElementById(targetId);
                if (!target) {
                    return;
                }

                const selectedOption = selectElement.options[selectElement.selectedIndex];
                const fte = selectedOption ? selectedOption.getAttribute('data-fte') : '';
                target.textContent = fte && fte !== '' ? fte : '-';
            };

            selects.forEach((selectElement) => {
                syncFteDisplay(selectElement);
                selectElement.addEventListener('change', () => syncFteDisplay(selectElement));
            });
        })();
    </script>
@endsection
