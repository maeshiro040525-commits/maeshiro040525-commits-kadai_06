<?php

namespace App\Http\Controllers\Setting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\StoreEmploeeRequest;
use App\Http\Requests\Setting\UpdateEmploeeRequest;
use App\Models\Corporate;
use App\Models\Emploee;
use App\Models\EmploeeAssignment;
use App\Models\EmploeeQualification;
use App\Models\FacilityStaffExternalCode;
use App\Models\Facility;
use App\Models\WorkStyle;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmploeeController extends Controller
{
    public function index(Request $request)
    {
        $corporate = Corporate::query()->orderBy('id')->first();
        $targetDate = $this->resolveTargetDate($request->query('target_date'));

        $facilities = $corporate
            ? Facility::query()
                ->where('corporate_id', $corporate->id)
                ->orderBy('id')
                ->get(['id', 'name'])
            : collect();
        $workStyles = $corporate
            ? WorkStyle::query()
                ->where('corporate_id', $corporate->id)
                ->currentVisible()
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name', 'fte'])
            : collect();

        $facilityId = (int) $request->query('facility_id', 0);
        $targetDateString = $targetDate->toDateString();
        // rowsにはfacility・name・qualification・employment_type・start_date・end_date・retired_flagを入れる。
        // target_date時点の配属情報を表示する。
        $rows = $corporate
            ? $this->buildRows($corporate->id, $facilityId, $targetDate)
            : collect();
        $emploeeMasterRows = $corporate
            ? Emploee::query()
                ->where('corporate_id', $corporate->id)
                ->with(['assignments' => function ($query) use ($targetDateString, $facilityId) {
                    $query
                        ->with(['facility', 'workStyle'])
                        ->whereDate('start_date', '<=', $targetDateString)
                        ->where(function ($periodQuery) use ($targetDateString) {
                            $periodQuery
                                ->whereNull('end_date')
                                ->orWhereDate('end_date', '>=', $targetDateString);
                        });

                    if ($facilityId > 0) {
                        $query->where('facility_id', $facilityId);
                    }

                    $query->orderBy('facility_id')->orderBy('start_date')->orderBy('id');
                }])
                // 資格履歴も一緒に読む（新しい適用月が上に来るよう降順）。画面の「資格履歴」簡易表示で使う。
                ->with(['qualifications' => function ($query) {
                    $query->orderByDesc('effective_from_ym')->orderByDesc('id');
                }])
                ->orderBy('id')
                ->get([
                    'id',
                    'corporate_id',
                    'name',
                    'external_staff_code',
                    'qualification',
                    'employment_start_date',
                    'employment_end_date',
                ])
                ->map(
                    fn (Emploee $emploee) => $this->mapEmploeeMasterRow($emploee, $targetDate)
                )
            : collect();

        return view('setting.emploees.index', [
            'corporate' => $corporate,
            'facilities' => $facilities,
            'workStyles' => $workStyles,
            'facilityId' => $facilityId,
            'targetDate' => $targetDate->toDateString(),
            'rows' => $rows,
            'emploeeMasterRows' => $emploeeMasterRows,
            // 資格ドロップダウンの選択肢（唯一の正）。Blade はこれをそのまま並べる。
            'qualificationVocabulary' => EmploeeQualification::VOCABULARY,
        ]);
    }

    public function store(StoreEmploeeRequest $request): RedirectResponse
    {
        $corporate = Corporate::query()->orderBy('id')->first();
        if ($corporate === null) {
            return $this->redirectToIndex($request)
                ->with('error', '先に法人（corporates）を1件作ってください。');
        }

        $data = $request->validated();

        $assignmentFacilityId = (int) $data['assignment_facility_id'];
        $facility = Facility::query()
            ->whereKey($assignmentFacilityId)
            ->where('corporate_id', $corporate->id)
            ->first();
        if ($facility === null) {
            throw ValidationException::withMessages([
                'assignment_facility_id' => '対象法人に紐づく施設を選択してください。',
            ]);
        }
        $assignmentWorkStyleId = (int) $data['assignment_work_style_id'];
        $workStyle = WorkStyle::query()
            ->whereKey($assignmentWorkStyleId)
            ->where('corporate_id', $corporate->id)
            ->currentVisible()
            ->first();
        if ($workStyle === null) {
            throw ValidationException::withMessages([
                'assignment_work_style_id' => '対象法人に紐づく勤務タイプを選択してください。',
            ]);
        }

        try {
            DB::transaction(function () use ($corporate, $data, $assignmentFacilityId, $workStyle): void {
                $emploee = Emploee::query()->create([
                    'corporate_id' => $corporate->id,
                    'name' => $data['name'],
                    'external_staff_code' => $this->normalizeExternalStaffCode($data['external_staff_code'] ?? null),
                    'qualification' => $data['qualification'],
                    'employment_start_date' => $data['employment_start_date'] ?? null,
                    'employment_end_date' => $data['employment_end_date'] ?? null,
                ]);

                // 入力した資格を「適用月から有効」として資格履歴にも記録する（時点管理の本体）。
                $this->upsertQualificationHistory((int) $emploee->id, $data);

                EmploeeAssignment::query()->create([
                    'staff_id' => $emploee->id,
                    'facility_id' => $assignmentFacilityId,
                    'work_style_id' => $workStyle->id,
                    'start_date' => $data['assignment_start_date'],
                    'end_date' => $data['assignment_end_date'] ?? null,
                    // TODO: 休職は将来的に複数期間が必要。現状は1期間のみ保存。
                    'leave_start_date' => $data['assignment_leave_start_date'] ?? null,
                    'leave_end_date' => $data['assignment_leave_end_date'] ?? null,
                    'employment_type' => $data['assignment_employment_type'],
                    'work_style' => $workStyle->name,
                    'fte' => $workStyle->fte,
                    'is_active' => true,
                ]);

                $this->syncFacilityExternalStaffCode(
                    (int) $assignmentFacilityId,
                    (int) $emploee->id,
                    $data['external_staff_code'] ?? null
                );
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'external_staff_code' => '同じ施設ですでに使用されている外部職員コードです。',
            ]);
        }

        return $this->redirectToIndex($request)
            ->with('success', '職員と配属情報を追加しました。');
    }

    public function update(UpdateEmploeeRequest $request, Emploee $emploee): RedirectResponse
    {
        $corporate = Corporate::query()->orderBy('id')->first();
        if ($corporate === null || (int) $emploee->corporate_id !== (int) $corporate->id) {
            return $this->redirectToIndex($request)
                ->with('error', '対象法人外の職員は更新できません。');
        }

        $data = $request->validated();

        $assignmentFacilityId = (int) $data['assignment_facility_id'];
        $facility = Facility::query()
            ->whereKey($assignmentFacilityId)
            ->where('corporate_id', $corporate->id)
            ->first();
        if ($facility === null) {
            throw ValidationException::withMessages([
                'assignment_facility_id' => '対象法人に紐づく施設を選択してください。',
            ]);
        }
        $assignmentWorkStyleId = (int) $data['assignment_work_style_id'];
        $workStyle = WorkStyle::query()
            ->whereKey($assignmentWorkStyleId)
            ->where('corporate_id', $corporate->id)
            ->currentVisible()
            ->first();
        if ($workStyle === null) {
            throw ValidationException::withMessages([
                'assignment_work_style_id' => '対象法人に紐づく勤務タイプを選択してください。',
            ]);
        }

        try {
            DB::transaction(function () use ($emploee, $data, $assignmentFacilityId, $workStyle): void {
                $emploee->update([
                    'name' => $data['name'],
                    'external_staff_code' => $this->normalizeExternalStaffCode($data['external_staff_code'] ?? null),
                    'qualification' => $data['qualification'],
                    'employment_start_date' => $data['employment_start_date'] ?? null,
                    'employment_end_date' => $data['employment_end_date'] ?? null,
                ]);

                // 資格を変更した場合、その「適用月から有効」として資格履歴に追記/上書きする。
                $this->upsertQualificationHistory((int) $emploee->id, $data);

                $assignmentPayload = [
                    'facility_id' => $assignmentFacilityId,
                    'work_style_id' => $workStyle->id,
                    'start_date' => $data['assignment_start_date'],
                    'end_date' => $data['assignment_end_date'] ?? null,
                    // TODO: 休職は将来的に複数期間が必要。現状は1期間のみ保存。
                    'leave_start_date' => $data['assignment_leave_start_date'] ?? null,
                    'leave_end_date' => $data['assignment_leave_end_date'] ?? null,
                    'employment_type' => $data['assignment_employment_type'],
                    'work_style' => $workStyle->name,
                    'fte' => $workStyle->fte,
                    'is_active' => true,
                ];

                $assignmentStartDate = CarbonImmutable::parse((string) $assignmentPayload['start_date'])->startOfDay();
                $assignmentId = isset($data['assignment_id']) ? (int) $data['assignment_id'] : null;
                $referenceAssignment = $this->resolveReferenceAssignmentForAppend(
                    (int) $emploee->id,
                    $assignmentId
                );

                $this->closeReferenceAssignmentForAppend(
                    $referenceAssignment,
                    $assignmentFacilityId,
                    $assignmentStartDate
                );
                // 同日・同施設の配属が既にあれば上書き、なければ新規作成
                $existingAssignment = $this->findDuplicateAssignment(
                    (int) $emploee->id,
                    $assignmentFacilityId,
                    $assignmentStartDate->toDateString()
                );

                if ($existingAssignment) {
                    $existingAssignment->update($assignmentPayload);
                } else {
                    EmploeeAssignment::query()->create(
                        $assignmentPayload + ['staff_id' => $emploee->id]
                    );
                }
                $this->syncFacilityExternalStaffCode(
                    (int) $assignmentFacilityId,
                    (int) $emploee->id,
                    $data['external_staff_code'] ?? null
                );
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'external_staff_code' => '同じ施設ですでに使用されている外部職員コードです。',
            ]);
        }

        return $this->redirectToIndex($request)
            ->with('success', '職員と配属情報を更新しました（配属履歴は追加で保存されています）。');
    }

    public function destroy(Request $request, Emploee $emploee): RedirectResponse
    {
        $corporate = Corporate::query()->orderBy('id')->first();
        if ($corporate === null || (int) $emploee->corporate_id !== (int) $corporate->id) {
            return $this->redirectToIndex($request)
                ->with('error', '対象法人外の職員は削除できません。');
        }

        try {
            DB::transaction(function () use ($emploee): void {
                EmploeeAssignment::query()
                    ->where('staff_id', $emploee->id)
                    ->delete();
                $emploee->delete();
            });
        } catch (Throwable) {
            return $this->redirectToIndex($request)
                ->with('error', '参照データがあるため職員を削除できませんでした。');
        }

        return $this->redirectToIndex($request)
            ->with('success', '職員を削除しました。');
    }

    /**
     * 職員の資格を「適用月から有効」として資格履歴(emploee_qualifications)へ記録する。
     *
     * updateOrCreate の意図：同じ職員・同じ適用月の行が既にあれば資格を上書き、無ければ新規作成。
     *   こうしておくと、同じ月で資格を入力し直しても履歴が二重に増えない（＝月ごとに1行に正規化）。
     *   別の月で入力すれば、その月から有効な新しい履歴行が積まれる（時点管理）。
     */
    private function upsertQualificationHistory(int $staffId, array $data): void
    {
        EmploeeQualification::query()->updateOrCreate(
            ['staff_id' => $staffId, 'effective_from_ym' => $data['qualification_effective_ym']],
            ['qualification' => $data['qualification']],
        );
    }

    /**
     * 一覧表示用に、職員・所属・勤務情報を整形して返す。
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function buildRows(int $corporateId, int $facilityId, CarbonImmutable $targetDate): Collection
    {
        $targetDateString = $targetDate->toDateString();

        $query = EmploeeAssignment::query()
            ->with(['emploee', 'facility', 'workStyle'])
            ->whereDate('start_date', '<=', $targetDateString)
            ->where(function ($periodQuery) use ($targetDateString) {
                $periodQuery
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', $targetDateString);
            })
            // WhereRelationは他テーブルに一回当てて、マッチしたやつを条件として使う
            ->whereRelation('emploee', 'corporate_id', $corporateId);

        if ($facilityId > 0) {
            $query->where('facility_id', $facilityId);
        }

        $query->orderBy('facility_id');
        $query->orderBy('start_date');

        $assignments = $query->orderBy('id')->get();

        return $assignments->map(
            fn (EmploeeAssignment $assignment) => $this->mapAssignmentRow($assignment, $targetDate)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAssignmentRow(EmploeeAssignment $assignment, CarbonImmutable $targetDate): array
    {
        $startDate = $assignment->start_date?->format('Y-m-d');
        $endDate = $assignment->end_date?->format('Y-m-d');

        $employmentEndDate = $assignment->emploee?->employment_end_date;
        $isRetired = $employmentEndDate !== null && $employmentEndDate->lt($targetDate);

        return [
            'facility' => $assignment->facility?->name ?? '-',
            'name' => $assignment->emploee?->name ?? '-',
            // 資格は実データの値（日本語の正式語彙）をそのまま表示する。
            // 旧実装は「保育士等／その他」の2区分に潰していたが、語彙統一(Step1 ⑥)で
            // 園長・保育教諭・子育て支援員等が全部「その他」に化ける弊害があったため廃止。
            'qualification' => $this->formatDisplayText($assignment->emploee?->qualification),
            'employment_type' => $this->formatDisplayText($assignment->employment_type),
            'start_date' => $startDate ?? '-',
            'end_date' => $endDate ?? '-',
            'retired_flag' => $isRetired ? '退職' : '在籍',
            'work_style' => $this->formatDisplayText($assignment->workStyle?->name ?? $assignment->work_style),
        ];
    }

    private function formatDisplayText(?string $value): string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : '-';
    }

    private function resolveTargetDate(mixed $rawDate): CarbonImmutable
    {
        $dateString = trim((string) $rawDate);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $dateString)->startOfDay();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEmploeeMasterRow(Emploee $emploee, CarbonImmutable $targetDate): array
    {
        $employmentEndDate = $emploee->employment_end_date;
        $isRetired = $employmentEndDate !== null && $employmentEndDate->lt($targetDate);
        $assignments = $emploee->assignments;
        /** @var EmploeeAssignment|null $primaryAssignment */
        $primaryAssignment = $assignments->first();

        // 資格履歴を「適用月＋資格」の配列に整形（既に降順eager load済み＝新しい月が先頭）。
        $qualificationHistory = $emploee->qualifications
            ->map(fn (EmploeeQualification $row) => [
                'effective_from_ym' => $row->effective_from_ym,
                'qualification' => $row->qualification,
            ])
            ->all();

        return [
            'emploee' => $emploee,
            'qualification_history' => $qualificationHistory,
            'assignment_count' => $assignments->count(),
            'assignment_id' => $primaryAssignment?->id,
            'assignment_facility_id' => $primaryAssignment?->facility_id,
            'assignment_employment_type' => $primaryAssignment?->employment_type,
            'assignment_work_style_id' => $primaryAssignment?->work_style_id,
            'assignment_work_style' => $primaryAssignment?->workStyle?->name ?? $primaryAssignment?->work_style,
            'assignment_fte' => $primaryAssignment !== null && $primaryAssignment->fte !== null
                ? number_format((float) $primaryAssignment->fte, 2, '.', '')
                : null,
            'assignment_start_date_raw' => $primaryAssignment?->start_date?->format('Y-m-d'),
            'assignment_end_date_raw' => $primaryAssignment?->end_date?->format('Y-m-d'),
            'assignment_leave_start_date_raw' => $primaryAssignment?->leave_start_date?->format('Y-m-d'),
            'assignment_leave_end_date_raw' => $primaryAssignment?->leave_end_date?->format('Y-m-d'),
            'retired_flag' => $isRetired ? '退職' : '在籍',
            'facility' => $this->joinAssignmentValues($assignments, fn (EmploeeAssignment $assignment) => $assignment->facility?->name),
            'employment_type' => $this->joinAssignmentValues($assignments, fn (EmploeeAssignment $assignment) => $assignment->employment_type),
            'work_style' => $this->joinAssignmentValues(
                $assignments,
                fn (EmploeeAssignment $assignment) => $assignment->workStyle?->name ?? $assignment->work_style
            ),
            'fte' => $this->joinAssignmentValues($assignments, function (EmploeeAssignment $assignment): ?string {
                return $assignment->fte !== null ? number_format((float) $assignment->fte, 2, '.', '') : null;
            }),
            'assignment_start_date' => $this->joinAssignmentValues(
                $assignments,
                fn (EmploeeAssignment $assignment) => $assignment->start_date?->format('Y-m-d')
            ),
            'assignment_end_date' => $this->joinAssignmentValues(
                $assignments,
                fn (EmploeeAssignment $assignment) => $assignment->end_date?->format('Y-m-d') ?? '継続'
            ),
            'leave_start_date' => $this->joinAssignmentValues(
                $assignments,
                fn (EmploeeAssignment $assignment) => $assignment->leave_start_date?->format('Y-m-d')
            ),
            'leave_end_date' => $this->joinAssignmentValues(
                $assignments,
                fn (EmploeeAssignment $assignment) => $assignment->leave_end_date?->format('Y-m-d')
            ),
        ];
    }

    /**
     * @param Collection<int, EmploeeAssignment> $assignments
     */
    private function joinAssignmentValues(Collection $assignments, callable $resolver): string
    {
        $values = $assignments
            ->map(function (EmploeeAssignment $assignment) use ($resolver): ?string {
                $value = $resolver($assignment);
                $trimmed = trim((string) $value);

                return $trimmed !== '' ? $trimmed : null;
            })
            ->filter()
            ->unique()
            ->values();

        return $values->isEmpty() ? '-' : $values->implode(' / ');
    }

    private function redirectToIndex(Request $request): RedirectResponse
    {
        $facilityId = (int) $request->input('facility_id', $request->query('facility_id', 0));
        $targetDate = trim((string) $request->input('target_date', $request->query('target_date', '')));
        $params = ['facility_id' => $facilityId];

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) {
            $params['target_date'] = $targetDate;
        }

        return redirect()->route('setting.emploees.index', $params);
    }

    private function normalizeExternalStaffCode(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function syncFacilityExternalStaffCode(int $facilityId, int $staffId, ?string $externalStaffCode): void
    {
        $normalized = $this->normalizeExternalStaffCode($externalStaffCode);
        if ($normalized === null) {
            FacilityStaffExternalCode::query()
                ->where('facility_id', $facilityId)
                ->where('staff_id', $staffId)
                ->delete();

            return;
        }

        FacilityStaffExternalCode::query()->updateOrCreate(
            [
                'facility_id' => $facilityId,
                'staff_id' => $staffId,
            ],
            [
                'external_staff_code' => $normalized,
            ]
        );
    }

    private function resolveReferenceAssignmentForAppend(int $staffId, ?int $assignmentId): ?EmploeeAssignment
    {
        if ($assignmentId === null || $assignmentId <= 0) {
            return null;
        }

        $assignment = EmploeeAssignment::query()
            ->whereKey($assignmentId)
            ->where('staff_id', $staffId)
            ->lockForUpdate()
            ->first();

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'assignment_id' => '更新対象の配属情報が見つかりません。',
            ]);
        }

        return $assignment;
    }

    private function closeReferenceAssignmentForAppend(
        ?EmploeeAssignment $referenceAssignment,
        int $nextFacilityId,
        CarbonImmutable $nextStartDate
    ): void {
        if ($referenceAssignment === null) {
            return;
        }

        if ((int) $referenceAssignment->facility_id !== $nextFacilityId) {
            return;
        }

        $referenceStartDate = $referenceAssignment->start_date?->toDateString();
        if ($referenceStartDate === null) {
            return;
        }

        // 同日の場合は上書き許可（後続で既存レコードを更新する）
        if ($nextStartDate->equalTo(CarbonImmutable::parse($referenceStartDate)->startOfDay())) {
            return;
        }

        if ($nextStartDate->lessThan(CarbonImmutable::parse($referenceStartDate)->startOfDay())) {
            throw ValidationException::withMessages([
                'assignment_start_date'
                    => '同一施設の配属履歴を追加する場合、配属開始日は既存配属より後の日付を入力してください。',
            ]);
        }

        $referenceEndDate = $referenceAssignment->end_date?->toDateString();
        if ($referenceEndDate !== null && CarbonImmutable::parse($referenceEndDate)->startOfDay()->lt($nextStartDate)) {
            return;
        }

        $referenceAssignment->update([
            'end_date' => $nextStartDate->subDay()->toDateString(),
        ]);
    }

    /**
     * 同一施設・同一開始日の配属が既にある場合は既存レコードを返す（上書き用）。
     * なければ null を返す（新規作成用）。
     */
    private function findDuplicateAssignment(
        int $staffId,
        int $facilityId,
        string $startDate
    ): ?EmploeeAssignment {
        return EmploeeAssignment::query()
            ->where('staff_id', $staffId)
            ->where('facility_id', $facilityId)
            ->whereDate('start_date', $startDate)
            ->first();
    }
}
