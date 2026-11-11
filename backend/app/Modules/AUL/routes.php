<?php

use App\Modules\AUL\AulAdminController;
use App\Modules\AUL\AulController;
use App\Modules\AUL\AulToolsController;
use App\Modules\AUL\QuestionImport;
use App\Modules\AUL\VtaCatalogController;
use App\Modules\AUL\VtaController;
use Illuminate\Support\Facades\Route;

// Venta de cursos (VTA): pedido y cobro (simulado en modo de prueba hasta configurar la pasarela).
Route::middleware(['auth:sanctum', 'company', 'module:VTA'])->prefix('vta')->group(function () {
    Route::post('sell', [VtaController::class, 'sell']);
    Route::post('orders/{id}/pay', [VtaController::class, 'pay'])->whereUuid('id');
    Route::get('catalog', [VtaCatalogController::class, 'link']);
    Route::put('catalog', [VtaCatalogController::class, 'setSlug']);
});

// Catálogo público de cursos de la empresa (sin sesión): lectura y solicitud de compra con límite de uso por IP (RN-VTA-24).
Route::prefix('public/courses/{catalog}')->where(['catalog' => '[A-Za-z0-9-]{3,60}', 'offer' => '[a-z0-9-]{1,120}'])->group(function () {
    Route::middleware('throttle:web-read')->get('/', [VtaCatalogController::class, 'index']);
    Route::middleware('throttle:web-read')->get('{offer}', [VtaCatalogController::class, 'show']);
    Route::middleware('throttle:web-forms')->post('{offer}/order', [VtaCatalogController::class, 'order']);
});

// Aula virtual para quien aprende: mis cursos, lecciones, examen; y alternativas de las preguntas.
Route::middleware(['auth:sanctum', 'company', 'module:AUL'])->prefix('aul')->group(function () {
    Route::get('my', [AulController::class, 'my']);
    Route::get('enrollments/{id}', [AulController::class, 'show'])->whereUuid('id');
    Route::post('enrollments/{id}/lessons/{lesson}/done', [AulController::class, 'done'])->whereUuid(['id', 'lesson']);
    Route::post('enrollments/{id}/lessons/{lesson}/attempts', [AulController::class, 'startAttempt'])->whereUuid(['id', 'lesson'])->middleware('throttle:20,1');
    Route::put('enrollments/{id}/attempts/{attempt}', [AulController::class, 'saveAnswers'])->whereUuid(['id', 'attempt'])->middleware('throttle:120,1');
    Route::post('enrollments/{id}/lessons/{lesson}/exam', [AulController::class, 'exam'])->whereUuid(['id', 'lesson'])->middleware('throttle:20,1');
    Route::post('enrollments/{id}/heartbeat', [AulController::class, 'heartbeat'])->whereUuid('id')->middleware('throttle:120,1');
    Route::put('questions/{id}/options', [AulController::class, 'options'])->whereUuid('id');
});

// Administración del aula (M-AUL SCR-AUL-01..19): panel, curso por dentro, banco de preguntas, capacitadores y recomendaciones.
Route::middleware(['auth:sanctum', 'company', 'module:AUL'])->prefix('aul/admin')->controller(AulAdminController::class)->group(function () {
    Route::get('overview', 'overview')->middleware('permission:aul.courses.read');
    Route::post('courses', 'createCourse')->middleware('permission:aul.courses.create');
    Route::get('courses/{id}', 'course')->whereUuid('id')->middleware('permission:aul.courses.read');
    Route::post('courses/{id}/publish', 'publish')->whereUuid('id')->middleware('permission:aul.courses.publish');
    Route::post('courses/{id}/sections', 'addSection')->whereUuid('id')->middleware('permission:aul.course_sections.create');
    Route::post('courses/{id}/lessons', 'addLesson')->whereUuid('id')->middleware('permission:aul.course_lessons.create');
    Route::put('courses/{id}/order', 'order')->whereUuid('id')->middleware('permission:aul.course_lessons.update');
    Route::get('courses/{id}/participants', 'participants')->whereUuid('id')->middleware('permission:aul.course_enrollments.read');
    Route::post('courses/{id}/enroll', 'enroll')->whereUuid('id')->middleware('permission:aul.course_enrollments.create');
    Route::get('courses/{id}/grades', 'grades')->whereUuid('id')->middleware('permission:aul.quiz_attempts.read');
    Route::get('banks', 'banks')->middleware('permission:aul.question_banks.read');
    Route::get('trainers', 'trainers')->middleware('permission:aul.trainer_permissions.read');
    Route::post('trainers/availability', 'addAvailability')->middleware('permission:aul.trainer_availability.create');
    Route::get('recommendations', 'recommendations')->middleware('permission:aul.course_recommendations.read');
    Route::post('recommendations', 'recommend')->middleware('permission:aul.course_recommendations.create');
    Route::post('recommendations/{id}/approve', 'approve')->whereUuid('id')->middleware('permission:aul.course_recommendations.update');
    Route::post('recommendations/{id}/dismiss', 'dismiss')->whereUuid('id')->middleware('permission:aul.course_recommendations.update');
});

// Herramientas del aula: estadística por pregunta, permanencia, vista previa, extender acceso, reiniciar intentos,
// «Por calificar», escalas de calificación, importación de preguntas y reglas de recomendación.
Route::middleware(['auth:sanctum', 'company', 'module:AUL'])->prefix('aul/admin')->group(function () {
    Route::controller(AulToolsController::class)->group(function () {
        Route::put('lessons/{id}/exam', 'examConfig')->whereUuid('id')->middleware('permission:aul.course_lessons.update');
        Route::get('courses/{id}/exam-stats', 'examStats')->whereUuid('id')->middleware('permission:aul.quiz_attempts.read');
        Route::get('courses/{id}/time', 'timeReport')->whereUuid('id')->middleware('permission:aul.learning_activity_sessions.read');
        Route::get('courses/{id}/preview', 'preview')->whereUuid('id')->middleware('permission:aul.courses.read');
        Route::get('courses/{id}/preview/lessons/{lesson}', 'previewQuestions')->whereUuid(['id', 'lesson'])->middleware('permission:aul.courses.read');
        Route::post('enrollments/{id}/extend', 'extend')->whereUuid('id')->middleware('permission:aul.course_enrollments.update');
        Route::post('enrollments/{id}/reset-attempts', 'resetAttempts')->whereUuid('id')->middleware('permission:aul.quiz_attempts.update');
        Route::get('grading', 'gradingQueue')->middleware('permission:aul.quiz_attempts.update');
        Route::get('attempts/{id}', 'attemptDetail')->whereUuid('id')->middleware('permission:aul.quiz_attempts.update');
        Route::post('attempts/{id}/grade', 'grade')->whereUuid('id')->middleware('permission:aul.quiz_attempts.update');
        Route::get('scales', 'scales')->middleware('permission:aul.grading_scales.read');
        Route::post('scales', 'createScale')->middleware('permission:aul.grading_scales.create');
        Route::put('scales/{id}', 'updateScale')->whereUuid('id')->middleware('permission:aul.grading_scales.update');
        Route::delete('scales/{id}', 'deleteScale')->whereUuid('id')->middleware('permission:aul.grading_scales.delete');
        Route::post('rules', 'createRule')->middleware('permission:aul.recommendation_rules.create');
        Route::put('rules/{id}', 'updateRule')->whereUuid('id')->middleware('permission:aul.recommendation_rules.update');
        Route::post('rules/{id}/run', 'runRule')->whereUuid('id')->middleware('permission:aul.recommendation_rules.generate');
    });
    Route::get('banks/{id}/import/template', [QuestionImport::class, 'template'])->whereUuid('id')->middleware('permission:aul.questions.import');
    Route::post('banks/{id}/import/preview', [QuestionImport::class, 'preview'])->whereUuid('id')->middleware(['permission:aul.questions.import', 'throttle:30,1']);
    Route::post('banks/{id}/import', [QuestionImport::class, 'import'])->whereUuid('id')->middleware(['permission:aul.questions.import', 'throttle:10,1']);
});
