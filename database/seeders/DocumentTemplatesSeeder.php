<?php

namespace Database\Seeders;

use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;

/**
 * Document template catalogue ported verbatim from the frontend mock
 * (frontend/dashboard/src/lib/mock-backend/seed.ts `documentTemplates`).
 * PLAN_PHASE_F1.md §4: fixed literal uuids so seeded documents can
 * reference them by uuid across reseeds. Idempotent via `updateOrCreate`
 * keyed on `code` (same convention as `PositionsSeeder`).
 */
class DocumentTemplatesSeeder extends Seeder
{
    public const TEMPLATES = [
        [
            'uuid' => '00000000-0000-4000-8000-00000000c001',
            'code' => 'BUYRUQ',
            'nameUz' => 'Buyruq',
            'descriptionUz' => "Tashkilot bo'yicha buyruq (tayinlash, o'tkazish, rag'batlantirish)",
            'bodyTemplate' => "{{SANA}} dagi {{RAQAM}}-sonli buyruqqa asosan {{XODIM_FIO}}\n"
                ."quyidagi vazifaga tayinlansin: {{MAZMUN}}.\n"
                .'Asos: {{ASOS}}.',
            'fields' => [
                ['key' => 'SANA', 'labelKey' => 'dashboard:documents.fields.SANA', 'kind' => 'date', 'required' => true],
                ['key' => 'RAQAM', 'labelKey' => 'dashboard:documents.fields.RAQAM', 'kind' => 'text', 'required' => true],
                ['key' => 'XODIM_FIO', 'labelKey' => 'dashboard:documents.fields.XODIM_FIO', 'kind' => 'employee', 'required' => true],
                ['key' => 'MAZMUN', 'labelKey' => 'dashboard:documents.fields.MAZMUN', 'kind' => 'textarea', 'required' => true],
                ['key' => 'ASOS', 'labelKey' => 'dashboard:documents.fields.ASOS', 'kind' => 'text', 'required' => true],
            ],
        ],
        [
            'uuid' => '00000000-0000-4000-8000-00000000c002',
            'code' => 'XIZMAT_XATI',
            'nameUz' => 'Xizmat xati',
            'descriptionUz' => "Bo'linmalararo ichki yozishmalar uchun xizmat xati",
            'bodyTemplate' => "{{KIMGA}}ga\n\n"
                ."{{SANA}} holatiga ko'ra quyidagi masala bo'yicha xizmat xati yo'llanmoqda:\n"
                ."{{MAZMUN}}\n\n"
                .'Ijro muddati: {{MUDDAT}}.',
            'fields' => [
                ['key' => 'KIMGA', 'labelKey' => 'dashboard:documents.fields.KIMGA', 'kind' => 'text', 'required' => true],
                ['key' => 'SANA', 'labelKey' => 'dashboard:documents.fields.SANA', 'kind' => 'date', 'required' => true],
                ['key' => 'MAZMUN', 'labelKey' => 'dashboard:documents.fields.MAZMUN', 'kind' => 'textarea', 'required' => true],
                ['key' => 'MUDDAT', 'labelKey' => 'dashboard:documents.fields.MUDDAT', 'kind' => 'date', 'required' => false],
            ],
        ],
        [
            'uuid' => '00000000-0000-4000-8000-00000000c003',
            'code' => 'MALUMOTNOMA',
            'nameUz' => "Ma'lumotnoma",
            'descriptionUz' => "Xodimning ish joyidan ma'lumotnoma",
            'bodyTemplate' => "Ushbu ma'lumotnoma {{XODIM_FIO}}ga berildi.\n"
                ."U haqiqatan ham tashkilotda {{LAVOZIM}} lavozimida faoliyat yuritadi.\n"
                ."Ma'lumotnoma {{MAQSAD}} taqdim etish uchun berildi.",
            'fields' => [
                ['key' => 'XODIM_FIO', 'labelKey' => 'dashboard:documents.fields.XODIM_FIO', 'kind' => 'employee', 'required' => true],
                ['key' => 'LAVOZIM', 'labelKey' => 'dashboard:documents.fields.LAVOZIM', 'kind' => 'text', 'required' => true],
                ['key' => 'MAQSAD', 'labelKey' => 'dashboard:documents.fields.MAQSAD', 'kind' => 'text', 'required' => true],
            ],
        ],
        [
            'uuid' => '00000000-0000-4000-8000-00000000c004',
            'code' => 'ARIZA',
            'nameUz' => 'Ariza',
            'descriptionUz' => "Xodimning rahbariyatga arizasi (ta'til, ruxsat, so'rov)",
            'bodyTemplate' => "Sizdan {{SANA}}dan boshlab menga {{MAZMUN}} berishingizni so'rayman.\n"
                .'Sabab: {{SABAB}}.',
            'fields' => [
                ['key' => 'SANA', 'labelKey' => 'dashboard:documents.fields.SANA', 'kind' => 'date', 'required' => true],
                ['key' => 'MAZMUN', 'labelKey' => 'dashboard:documents.fields.MAZMUN', 'kind' => 'textarea', 'required' => true],
                ['key' => 'SABAB', 'labelKey' => 'dashboard:documents.fields.SABAB', 'kind' => 'text', 'required' => true],
            ],
        ],
        [
            'uuid' => '00000000-0000-4000-8000-00000000c005',
            'code' => 'BILDIRISHNOMA',
            'nameUz' => 'Bildirishnoma',
            'descriptionUz' => "Barcha bo'linmalarga yo'naltiriladigan rasmiy bildirishnoma",
            'bodyTemplate' => "Barcha bo'linmalar e'tiboriga!\n\n"
                ."{{SANA}} kunidan boshlab {{MAZMUN}}.\n"
                ."Mas'ul shaxs: {{MASUL_FIO}}.",
            'fields' => [
                ['key' => 'SANA', 'labelKey' => 'dashboard:documents.fields.SANA', 'kind' => 'date', 'required' => true],
                ['key' => 'MAZMUN', 'labelKey' => 'dashboard:documents.fields.MAZMUN', 'kind' => 'textarea', 'required' => true],
                ['key' => 'MASUL_FIO', 'labelKey' => 'dashboard:documents.fields.MASUL_FIO', 'kind' => 'employee', 'required' => true],
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $t) {
            DocumentTemplate::updateOrCreate(
                ['code' => $t['code']],
                [
                    'uuid' => $t['uuid'],
                    'name_uz' => $t['nameUz'],
                    'description_uz' => $t['descriptionUz'],
                    'body_template' => $t['bodyTemplate'],
                    'fields' => $t['fields'],
                ],
            );
        }
    }
}
