<?php

/**
 * @file
 * Definition of Drupal\media_entity\MediaFormController.
 */

namespace Drupal\media_entity;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityFormController;

/**
 * Form controller for the media edit forms.
 */
class MediaFormController extends ContentEntityFormController {

  /**
   * Default settings for this media bundle.
   *
   * @var array
   */
  protected $settings;

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\media_entity\Entity\Media
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  protected function prepareEntity() {
    $media = $this->entity;
    // Set up default values, if required.
    $bundle = entity_load('media_bundle', $media->bundle());
    $this->settings = $bundle->getModuleSettings('media');
    $this->settings += array(
      'options' => array('status'),
    );

    // If this is a new media, fill in the default values.
    if ($media->isNew()) {
      foreach (array('status') as $key) {
        // Multistep media forms might have filled in something already.
        if ($media->$key->isEmpty()) {
          $media->$key = (int) in_array($key, $this->settings['options']);
        }
      }
      global $user;
      $media->setPublisherId($user->id());
      $media->setCreatedTime(REQUEST_TIME);
    }
    else {
      $media->date = format_date($media->getCreatedTime(), 'custom', 'Y-m-d H:i:s O');
      // Remove the log message from the original media entity.
      $media->log = NULL;
    }
    // Always use the default revision setting.
    $media->setNewRevision(in_array('revision', $this->settings['options']));
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   */
  public function form(array $form, array &$form_state) {
    $media = $this->entity;
    $media_bundle = entity_load('media_bundle', $media->getBundle());

    if ($this->operation == 'edit') {
      $form['#title'] = $this->t('<em>Edit @bundle</em> @title', array('@bundle' => $media_bundle->label(), '@title' => $media->label()));
    }

    // Changed must be sent to the client, for later overwrite error checking.
    $form['changed'] = array(
      '#type' => 'hidden',
      '#default_value' => $media->getChangedTime(),
    );

    $form['name'] = array(
      '#type' => 'textfield',
      '#title' => t('Media title'),
      '#required' => TRUE,
      '#default_value' => $media->label(),
      '#maxlength' => 255,
      '#weight' => -5,
    );

    $form['advanced'] = array(
      '#type' => 'vertical_tabs',
      '#attributes' => array('class' => array('entity-meta')),
      '#weight' => 99,
    );

    // Add a log field if the "Create new revision" option is checked, or if
    // the current user has the ability to check that option.
    $form['revision_information'] = array(
      '#type' => 'details',
      '#group' => 'advanced',
      '#title' => t('Revision information'),
      // Collapsed by default when "Create new revision" is unchecked.
      '#collapsed' => !$media->isNewRevision(),
      '#attributes' => array(
        'class' => array('media-form-revision-information'),
      ),
      '#weight' => 20,
      '#access' => $media->isNewRevision() || user_access('administer media'),
    );

    $form['revision_information']['revision']['revision'] = array(
      '#type' => 'checkbox',
      '#title' => t('Create new revision'),
      '#default_value' => $media->isNewRevision(),
      '#access' => user_access('administer media'),
    );

    $form['revision_information']['revision']['log'] = array(
      '#type' => 'textarea',
      '#title' => t('Revision log message'),
      '#rows' => 4,
      '#default_value' => !empty($media->log->value) ? $media->log->value : '',
      '#description' => t('Briefly describe the changes you have made.'),
      '#states' => array(
        'visible' => array(
          ':input[name="revision"]' => array('checked' => TRUE),
        ),
      ),
    );

    // Media publisher information for administrators.
    $form['publisher'] = array(
      '#type' => 'details',
      '#access' => user_access('administer media'),
      '#title' => t('Authoring information'),
      '#collapsed' => TRUE,
      '#group' => 'advanced',
      '#attributes' => array(
        'class' => array('media-form-publisher'),
      ),
      '#weight' => 90,
    );

    $form['publisher']['publisher_name'] = array(
      '#type' => 'textfield',
      '#title' => t('Published by'),
      '#maxlength' => 60,
      '#autocomplete_route_name' => 'user.autocomplete',
      '#default_value' => $media->getPublisher() ? $media->getPublisher()->getUsername() : '',
      '#weight' => -1,
      '#description' => t('Leave blank for anonymous.'),
    );
    $form['publisher']['date'] = array(
      '#type' => 'textfield',
      '#title' => t('Authored on'),
      '#maxlength' => 25,
      '#description' => t('Format: %time. The date format is YYYY-MM-DD and %timezone is the time zone offset from UTC. Leave blank to use the time of form submission.', array('%time' => !empty($media->date) ? date_format(date_create($media->date), 'Y-m-d H:i:s O') : format_date($media->getCreatedTime(), 'custom', 'Y-m-d H:i:s O'), '%timezone' => !empty($media->date) ? date_format(date_create($media->date), 'O') : format_date($media->getCreatedTime(), 'custom', 'O'))),
      '#default_value' => !empty($media->date) ? $media->date : '',
    );

    return parent::form($form, $form_state, $media);
  }

  /**
   * Updates the media by processing the submitted values.
   *
   * Overrides Drupal\Core\Entity\EntityFormController::submit().
   */
  public function submit(array $form, array &$form_state) {
    // Build the media object from the submitted values.
    $media = parent::submit($form, $form_state);

    // Save as a new revision if requested to do so.
    if (!empty($form_state['values']['revision'])) {
      $media->setNewRevision();
    }

    return $media;
  }

  /**
   * {@inheritdoc}
   */
  public function buildEntity(array $form, array &$form_state) {
    $entity = parent::buildEntity($form, $form_state);
    // A user might assign the media publisher by entering a user name in the node
    // form, which we then need to translate to a user ID.
    if (!empty($form_state['values']['publisher_name']) && $account = user_load_by_name($form_state['values']['publisher_name'])) {
      $entity->setPublisherId($account->id());
    }
    else {
      $entity->setPublisherId(0);
    }

    if (!empty($form_state['values']['date']) && $form_state['values']['date'] instanceOf DrupalDateTime) {
      $entity->setCreatedTime($form_state['values']['date']->getTimestamp());
    }
    else {
      $entity->setCreatedTime(REQUEST_TIME);
    }
    return $entity;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::save().
   */
  public function save(array $form, array &$form_state) {
    $media = $this->entity;
    $media->save();

    if ($media->id()) {
      $form_state['values']['mid'] = $media->id();
      $form_state['mid'] = $media->id();
      if ($media->access('view')) {
        $form_state['redirect_route'] = array(
          'route_name' => 'media.view',
          'route_parameters' => array(
            'media' => $media->id(),
          ),
        );
      }
      else {
        $form_state['redirect_route']['route_name'] = '<front>';
      }
    }
    else {
      // In the unlikely case something went wrong on save, the media will be
      // rebuilt and media form redisplayed the same way as in preview.
      drupal_set_message(t('The media could not be saved.'), 'error');
      $form_state['rebuild'] = TRUE;
    }
  }

}
