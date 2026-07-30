<?php

namespace App\Controller\Admin;

use App\Entity\Merchant;
use App\Entity\MerchantMember;
use App\Entity\User;
use App\Repository\MerchantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[Route('/admin/merchants')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class MerchantController extends AbstractController
{
    #[Route('', name: 'admin_merchant_index', methods: ['GET'])]
    public function index(MerchantRepository $merchants): Response
    {
        return $this->render('admin/merchant/index.html.twig', [
            'merchants' => $merchants->findBy([], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/new', name: 'admin_merchant_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        MerchantRepository $merchants,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        SluggerInterface $slugger,
        EntityManagerInterface $em,
    ): Response {
        $form = $this->createFormBuilder()
            ->add('name', TextType::class, [
                'label' => 'Merchant adı',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('adminName', TextType::class, [
                'label' => 'Yetkili adı',
                'constraints' => [new Assert\NotBlank()],
            ])
            ->add('adminEmail', EmailType::class, [
                'label' => 'Yetkili e-posta',
                'constraints' => [new Assert\NotBlank(), new Assert\Email()],
            ])
            ->add('adminPassword', PasswordType::class, [
                'label' => 'Yetkili şifre',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 6)],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($users->findOneBy(['email' => $data['adminEmail']])) {
                $this->addFlash('error', 'Bu e-posta zaten kullanımda.');

                return $this->redirectToRoute('admin_merchant_new');
            }

            $merchant = new Merchant();
            $merchant->setName($data['name']);
            $merchant->setSlug($slugger->slug($data['name'])->lower() . '-' . substr(uniqid(), -5));
            $merchants->save($merchant, false);

            $admin = new User();
            $admin->setName($data['adminName']);
            $admin->setEmail($data['adminEmail']);
            $admin->setPassword($hasher->hashPassword($admin, $data['adminPassword']));
            $users->save($admin, false);

            $membership = new MerchantMember();
            $membership->setMerchant($merchant);
            $membership->setUser($admin);
            $membership->setRole(MerchantMember::ROLE_OWNER);
            $em->persist($membership);
            $em->flush();

            $this->addFlash('success', sprintf('Merchant "%s" ve yetkilisi oluşturuldu.', $merchant->getName()));

            return $this->redirectToRoute('admin_merchant_index');
        }

        return $this->render('admin/merchant/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_merchant_toggle', methods: ['POST'])]
    public function toggle(Request $request, Merchant $merchant, MerchantRepository $merchants): Response
    {
        if (!$this->isCsrfTokenValid('toggle' . $merchant->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $merchant->setActive(!$merchant->isActive());
        $merchants->save($merchant);
        $this->addFlash('success', 'Merchant durumu güncellendi.');

        return $this->redirectToRoute('admin_merchant_index');
    }

    #[Route('/{id}', name: 'admin_merchant_delete', methods: ['POST'])]
    public function delete(Request $request, Merchant $merchant, MerchantRepository $merchants): Response
    {
        if (!$this->isCsrfTokenValid('delete' . $merchant->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $merchants->remove($merchant);
        $this->addFlash('success', 'Merchant silindi.');

        return $this->redirectToRoute('admin_merchant_index');
    }
}
