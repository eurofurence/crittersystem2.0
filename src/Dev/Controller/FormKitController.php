<?php

namespace App\Dev\Controller;

use App\Form\FormKitDemoType;
use App\Form\Model\FormKitDemoData;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('global:admin')]
final class FormKitController extends AbstractController
{
    public function __construct(private readonly FormFactoryInterface $forms)
    {
    }

    #[Route('/dev/kit', name: 'app_form_kit')]
    public function index(Request $request): Response
    {
        $data = new FormKitDemoData();

        $form = $this->createForm(FormKitDemoType::class, $data);
        $form->handleRequest($request);

        $submitted = $form->isSubmitted() && $form->isValid();

        return $this->render('form_kit/index.html.twig', [
            'pageTitle' => 'UI Kit — Forms',
            'form' => $form,
            'submitted' => $submitted,
            'data' => $data,
            // Two more never-submitted demo forms; separate names so their field ids do not collide.
            'extras' => $this->demoForm('kit_extras')->createView(),
            'narrow' => $this->demoForm('kit_narrow')->createView(),
        ]);
    }

    #[Route('/dev/kit/search-frame', name: 'app_form_kit_search_frame')]
    public function searchFrame(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));

        // Obviously fake data — this page must never show real departments.
        $items = [
            'Demo Department',
            'Demo Registration',
            'Demo Logistics',
            'Demo Security',
            'Demo Info Desk',
            'Demo Stage Crew',
            'Demo Sponsors',
        ];

        if ('' !== $q) {
            $items = array_values(array_filter(
                $items,
                static fn (string $v) => str_contains(mb_strtolower($v), mb_strtolower($q)),
            ));
        }

        return $this->render('form_kit/_search_frame.html.twig', [
            'q' => $q,
            'items' => $items,
        ]);
    }

    /**
     * A never-submitted demo form: it exists purely so the kit page can show the field
     * variants FormKitDemoType does not cover (help text, prefix/suffix, checkbox, single
     * choice, a disabled field, and a field carrying a validation error).
     */
    private function demoForm(string $name): FormInterface
    {
        $form = $this->forms->createNamedBuilder(
            $name,
            FormType::class,
            ['email' => 'not-an-email', 'budget' => '120', 'apiKey' => 'demo-key-0000-0000'],
            ['csrf_protection' => false, 'method' => 'GET', 'action' => $this->generateUrl('app_form_kit')],
        )
            ->add('email', TextType::class, ['label' => 'Contact e-mail', 'required' => false])
            ->add('budget', TextType::class, ['label' => 'Budget', 'required' => false])
            ->add('apiKey', TextType::class, ['label' => 'API key', 'required' => false, 'disabled' => true])
            ->add('newsletter', CheckboxType::class, ['label' => 'Send me the demo newsletter', 'required' => false])
            ->add('department', ChoiceType::class, [
                'label' => 'Department',
                'required' => false,
                'placeholder' => 'Choose a department…',
                'choices' => [
                    'Demo Department' => 'demo',
                    'Demo Registration' => 'demo-reg',
                    'Demo Logistics' => 'demo-log',
                ],
            ])
            ->add('skills', ChoiceType::class, [
                'label' => 'Demo skills',
                'required' => false,
                'multiple' => true,
                'choices' => [
                    'Demo Forklift' => 'forklift',
                    'Demo First Aid' => 'first-aid',
                    'Demo Radio' => 'radio',
                    'Demo Barista' => 'barista',
                ],
            ])
            ->getForm();

        // The error state, without needing an actual round-trip: this is exactly what a failed
        // constraint leaves behind, so the macro renders the same markup as on a real submit.
        $form->get('email')->addError(new FormError('This value is not a valid e-mail address (try example@demo.invalid).'));

        return $form;
    }
}
