<?php
namespace Drupal\orcid\Controller;

use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Drupal\Core\Database\Database;
use Drupal\Core\Controller\ControllerBase;

class OauthController extends ControllerBase
{
    public function redirectPage() {
        $element = array();
        $config = \Drupal::config('orcid.settings');
        //http://members.orcid.org/api/tokens-through-3-legged-oauth-authorization
        //Public API only at this moment
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $config->get('client_id'),    // The client ID assigned to you by the provider
            'clientSecret'            => $config->get('client_secret'),   // The client password assigned to you by the provider
            'redirectUri'             => Url::fromUri('base:/orcid/oauth', array('absolute' => TRUE))->toString(),
            'urlAuthorize'            => 'https://orcid.org/oauth/authorize',
            'urlAccessToken'          => 'https://pub.orcid.org/oauth/token',
            'urlResourceOwnerDetails' => 'http://pub.orcid.org/v1.2'
        ]);
        $provider = new \League\OAuth2\Client\Provider\GenericProvider([
            'clientId'                => $config->get('client_id'),    // The client ID assigned to you by the provider
            'clientSecret'            => $config->get('client_secret'),   // The client password assigned to you by the provider
            'redirectUri'             => Url::fromUri('base:/orcid/oauth', array('absolute' => TRUE))->toString(),
            'urlAuthorize'            => 'https://sandbox.orcid.org/oauth/authorize',
            'urlAccessToken'          => 'https://pub.sandbox.orcid.org/oauth/token',
            'urlResourceOwnerDetails' => 'http://pub.sandbox.orcid.org/v1.2'
        ]);
        if (!isset($_GET['code'])) {
            $options = [
                'scope' => ['/authenticate']
            ];
            $authorizationUrl = $provider->getAuthorizationUrl($options);
            //$_SESSION['oauth2state'] = $provider->getState();
            $response = new TrustedRedirectResponse($authorizationUrl);
            return $response;
            //header('Location: ' . $authorizationUrl);
            //exit;
        }
/*        elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {
            unset($_SESSION['oauth2state']);
        }*/
        try {
            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => $_GET['code']
            ]);

            $token = $accessToken->getToken();
            $values = $accessToken->getValues();
            $account = \Drupal::currentUser()->getAccount();

            $query = Database::getConnection()
                ->select('orcid', 'o')
                ->fields('o', array('uid'))
                ->condition('orcid', $values['orcid'], '=');
            $result = $query->execute();

            foreach ($result as $item) {
                //ORCID in record
                $uid = $item->uid;
                //anonymous user
                if ($account->id() == 0) {
                    if ($user = User::load($uid)) {
                        user_login_finalize($user);
                        $element = array(
                            '#markup' => 'Login with ORCID OAUTH!',
                        );
                        //$response = new RedirectResponse($_GET['redirect']);
                        return $element;
                    }
                }

                if ($account->id() == $uid) {//ORCID match UID
                    $element = array(
                        '#markup' => 'Your ORCID has been validated!',
                    );
                    return $element;
                } else {
                    //TODO: What if user account can't match ORCID record
                }
            }
            //Existing User
            if ($account->id()) {
                $query = Database::getConnection()
                    ->insert('orcid')
                    ->fields(array('orcid' => $values['orcid'], 'uid' => $account->id()))
                    ->execute();
                $element = array(
                    '#markup' => 'Your ORCID has been connected with your account!',
                );
                return $element;
            }
            //New user with New ORCID
            if ($account->id() == 0) {
                $user = User::create(array(
                    'name' => $values['orcid'] . '@' . $token,
                    'mail' => '',
                    'pass' => $token,
                    'status' => 1,
                ));
                $user->save();
                $query = Database::getConnection()
                    ->insert('orcid')
                    ->fields(array('orcid' => $values['orcid'], 'uid' => $user->id()))
                    ->execute();
                user_login_finalize($user);
                $element = array(
                    '#markup' => 'Account has been created with your ORCID!',
                );
                return $element;
            }
            /*
            $message .= $accessToken->getRefreshToken() . "\n";
            $message .= $accessToken->getExpires() . "\n";
            $message .= ($accessToken->hasExpired() ? 'expired' : 'not expired') . "\n";
            */

        } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
            \Drupal::logger('orcid')->error($e->getMessage());
            //exit($e->getMessage());
        }
        return $element;
    }
}